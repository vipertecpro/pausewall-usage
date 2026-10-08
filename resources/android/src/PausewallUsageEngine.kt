package com.vipertecpro.plugins.pausewallusage

import android.app.usage.UsageEvents
import android.app.usage.UsageStatsManager
import android.content.Context
import java.time.LocalDate
import java.time.ZoneId

/** Foreground time and opens of one app. */
data class AppUsage(
    var foregroundMillis: Long = 0,
    var opens: Int = 0,
)

/** A usage event reduced to what the counting needs. */
data class ForegroundEvent(
    val packageName: String?,
    val kind: Kind,
    val timeMillis: Long,
) {
    enum class Kind { RESUMED, PAUSED, SCREEN_OFF }
}

/**
 * Turns Android's usage events into foreground time and opens per app. The same counting rules
 * as Pausewall for NativePHP's usage(): one app is in the foreground at a time, screen off ends
 * it, an app open at midnight counts from midnight, and an "open" is a switch into a package
 * from a different one (or from screen off).
 */
object PausewallUsageEngine {
    fun startOfDay(date: LocalDate, zone: ZoneId): Long = date.atStartOfDay(zone).toInstant().toEpochMilli()

    /**
     * Foreground time and opens per package between [fromMillis] and [toMillis] from time-ordered
     * [events]. Events before [fromMillis] only establish which app was already open.
     */
    fun computeUsage(events: List<ForegroundEvent>, fromMillis: Long, toMillis: Long): Map<String, AppUsage> {
        val usage = HashMap<String, AppUsage>()
        var current: String? = null
        var since = 0L
        var lastForeground: String? = null

        fun close(at: Long) {
            val pkg = current ?: return
            val start = maxOf(since, fromMillis)
            val end = minOf(at, toMillis)
            if (end > start) usage.getOrPut(pkg) { AppUsage() }.foregroundMillis += end - start
            current = null
        }

        for (event in events) {
            if (event.timeMillis > toMillis) break
            when (event.kind) {
                ForegroundEvent.Kind.RESUMED -> {
                    val pkg = event.packageName ?: continue
                    if (current == pkg) continue
                    close(event.timeMillis)
                    current = pkg
                    since = event.timeMillis
                    if (pkg != lastForeground && event.timeMillis >= fromMillis) usage.getOrPut(pkg) { AppUsage() }.opens += 1
                    lastForeground = pkg
                }
                ForegroundEvent.Kind.PAUSED -> if (current != null && current == event.packageName) close(event.timeMillis)
                ForegroundEvent.Kind.SCREEN_OFF -> {
                    close(event.timeMillis)
                    lastForeground = null
                }
            }
        }
        close(toMillis)
        return usage
    }

    /** Usage for [days] local days, newest first: (date, package → usage). One query per day, on demand. */
    fun usageDays(context: Context, days: Int): List<Pair<LocalDate, Map<String, AppUsage>>> {
        val zone = ZoneId.systemDefault()
        val now = System.currentTimeMillis()
        val today = LocalDate.now(zone)
        return (0 until days).map { offset ->
            val date = today.minusDays(offset.toLong())
            val from = startOfDay(date, zone)
            val to = minOf(startOfDay(date.plusDays(1), zone), now)
            date to computeUsage(events(context, from - 60 * 60_000L, to), from, to)
        }
    }

    /** Usage events between [from] and [to] reduced to foreground changes; empty without usage access. */
    fun events(context: Context, from: Long, to: Long): List<ForegroundEvent> {
        val manager = context.getSystemService(Context.USAGE_STATS_SERVICE) as? UsageStatsManager ?: return emptyList()
        val result = ArrayList<ForegroundEvent>()
        val events = runCatching { manager.queryEvents(from, to) }.getOrNull() ?: return emptyList()
        val event = UsageEvents.Event()
        while (events.hasNextEvent()) {
            events.getNextEvent(event)
            val kind = when (event.eventType) {
                UsageEvents.Event.ACTIVITY_RESUMED -> ForegroundEvent.Kind.RESUMED
                UsageEvents.Event.ACTIVITY_PAUSED, UsageEvents.Event.ACTIVITY_STOPPED -> ForegroundEvent.Kind.PAUSED
                UsageEvents.Event.SCREEN_NON_INTERACTIVE, UsageEvents.Event.KEYGUARD_SHOWN, UsageEvents.Event.DEVICE_SHUTDOWN -> ForegroundEvent.Kind.SCREEN_OFF
                else -> null
            } ?: continue
            result += ForegroundEvent(event.packageName, kind, event.timeStamp)
        }
        return result
    }
}
