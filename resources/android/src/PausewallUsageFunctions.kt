package com.vipertecpro.plugins.pausewallusage

import android.Manifest
import android.app.AppOpsManager
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.os.Process
import android.provider.Settings
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import org.json.JSONArray
import org.json.JSONObject
import java.time.LocalDate
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

// Pausewall Usage for NativePHP: read-only app usage. No service, no receiver, no background
// work: every call reads Android's usage statistics once and returns. Payloads match the PHP
// side (src/PausewallUsage.php).

/** Reason strings reported next to an empty result. */
object Reason {
    const val PERMISSION_MISSING = "permissionMissing"
    const val ALREADY_GRANTED = "alreadyGranted"
    const val NO_SETTINGS_SCREEN = "noSettingsScreen"
}

object PausewallUsageFunctions {
    /** PausewallUsage.Permission {} → {status: granted|denied|notDetermined}. */
    class Permission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            BridgeResponse.success(mapOf("status" to PausewallUsageAccess.status(activity)))
    }

    /** PausewallUsage.RequestPermission {} → {opened, reason?}: opens the Usage access settings for the app. */
    class RequestPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (PausewallUsageAccess.isGranted(activity)) {
                return BridgeResponse.success(mapOf("opened" to false, "reason" to Reason.ALREADY_GRANTED))
            }
            PausewallUsageAccess.markRequested(activity)
            return BridgeResponse.success(
                if (PausewallUsageAccess.openSettings(activity)) {
                    mapOf("opened" to true)
                } else {
                    mapOf("opened" to false, "reason" to Reason.NO_SETTINGS_SCREEN)
                },
            )
        }
    }

    /** PausewallUsage.Usage {days: 1..7} → {days: [{date, totalSeconds, apps: [{id, name, foregroundSeconds, opens}]}]} newest first. */
    class Usage(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (!PausewallUsageAccess.isGranted(activity)) {
                return BridgeResponse.success(mapOf("days" to JSONArray(), "reason" to Reason.PERMISSION_MISSING))
            }
            val days = Params.int(parameters["days"], 1).coerceIn(1, 7)
            val report = PausewallUsageReport(activity)
            val result = PausewallUsageEngine.usageDays(activity, days).map { (date, usage) ->
                report.day(date.toString(), usage, Int.MAX_VALUE)
            }
            return BridgeResponse.success(mapOf("days" to JSONArray(result)))
        }
    }

    /** PausewallUsage.Today {limit?: 1..50} → {date, totalSeconds, apps: [...]}: today's total and the most used apps. */
    class Today(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (!PausewallUsageAccess.isGranted(activity)) {
                return BridgeResponse.success(
                    mapOf("date" to LocalDate.now().toString(), "totalSeconds" to 0, "apps" to JSONArray(), "reason" to Reason.PERMISSION_MISSING),
                )
            }
            val limit = Params.int(parameters["limit"], 10).coerceIn(1, 50)
            val (date, usage) = PausewallUsageEngine.usageDays(activity, 1).first()
            val day = PausewallUsageReport(activity).day(date.toString(), usage, limit)
            return BridgeResponse.success(
                mapOf("date" to day.getString("date"), "totalSeconds" to day.getLong("totalSeconds"), "apps" to day.getJSONArray("apps")),
            )
        }
    }
}

/** Builds the day payload: launchable apps only, at least a second or one open, named, longest first. */
class PausewallUsageReport(private val context: Context) {
    private val launchable: Set<String> by lazy { launchablePackages() }
    private val names = HashMap<String, String>()

    /** [limit] caps the listed apps; the total always counts every app. */
    fun day(date: String, usage: Map<String, AppUsage>, limit: Int): JSONObject {
        val apps = usage
            .filter { (pkg, u) -> pkg in launchable && (u.foregroundMillis >= 1000 || u.opens > 0) }
            .map { (pkg, u) ->
                JSONObject()
                    .put("id", pkg)
                    .put("name", names.getOrPut(pkg) { label(pkg) })
                    .put("foregroundSeconds", u.foregroundMillis / 1000)
                    .put("opens", u.opens)
            }
            .sortedWith(compareByDescending<JSONObject> { it.optLong("foregroundSeconds") }.thenBy { it.optString("name") })
        val total = apps.sumOf { it.optLong("foregroundSeconds") }
        return JSONObject().put("date", date).put("totalSeconds", total).put("apps", JSONArray(apps.take(limit)))
    }

    /** Packages with a launcher activity (visible through the manifest's <queries> launcher intent). */
    private fun launchablePackages(): Set<String> {
        val intent = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER)
        return context.packageManager.queryIntentActivities(intent, 0).map { it.activityInfo.packageName }.toSet()
    }

    private fun label(pkg: String): String = runCatching {
        val pm = context.packageManager
        pm.getApplicationLabel(pm.getApplicationInfo(pkg, 0)).toString()
    }.getOrDefault(pkg)
}

/** Usage access: the only permission this plugin declares (PACKAGE_USAGE_STATS, granted in Settings). */
object PausewallUsageAccess {
    private const val PREFS = "pausewall_usage"
    private const val REQUESTED = "requested"

    fun isGranted(context: Context): Boolean = mode(context) == OpMode.ALLOWED

    /** granted, or notDetermined until the app has asked once, denied after that. */
    fun status(context: Context): String = when (mode(context)) {
        OpMode.ALLOWED -> "granted"
        OpMode.DENIED -> "denied"
        OpMode.DEFAULT -> if (wasRequested(context)) "denied" else "notDetermined"
    }

    enum class OpMode { ALLOWED, DENIED, DEFAULT }

    private fun mode(context: Context): OpMode {
        val ops = context.getSystemService(Context.APP_OPS_SERVICE) as AppOpsManager
        return when (ops.unsafeCheckOpNoThrow(AppOpsManager.OPSTR_GET_USAGE_STATS, Process.myUid(), context.packageName)) {
            AppOpsManager.MODE_ALLOWED -> OpMode.ALLOWED
            AppOpsManager.MODE_DEFAULT ->
                if (context.checkCallingOrSelfPermission(Manifest.permission.PACKAGE_USAGE_STATS) == PackageManager.PERMISSION_GRANTED) OpMode.ALLOWED else OpMode.DEFAULT
            else -> OpMode.DENIED
        }
    }

    private fun wasRequested(context: Context): Boolean =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getBoolean(REQUESTED, false)

    fun markRequested(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putBoolean(REQUESTED, true).apply()
    }

    /** Opens the Usage access screen for this app, or the general list when the system has no per-app screen. */
    fun openSettings(activity: FragmentActivity): Boolean = onMainSync(false) {
        val pkg = Uri.fromParts("package", activity.packageName, null)
        listOf(
            Intent(Settings.ACTION_USAGE_ACCESS_SETTINGS).setData(pkg),
            Intent(Settings.ACTION_USAGE_ACCESS_SETTINGS),
        ).any { intent ->
            try {
                activity.startActivity(intent)
                true
            } catch (e: ActivityNotFoundException) {
                false
            } catch (e: SecurityException) {
                false
            }
        }
    }

    /** Runs [block] on the main thread and waits for its result (at most two seconds). */
    private fun <T> onMainSync(default: T, block: () -> T): T {
        if (Looper.myLooper() == Looper.getMainLooper()) return block()
        var result = default
        val latch = CountDownLatch(1)
        Handler(Looper.getMainLooper()).post {
            try {
                result = block()
            } finally {
                latch.countDown()
            }
        }
        latch.await(2, TimeUnit.SECONDS)
        return result
    }
}

/** Reads loosely typed bridge parameters (org.json values) safely. */
object Params {
    fun int(value: Any?, default: Int): Int = when (value) {
        is Number -> value.toInt()
        is String -> value.trim().toDoubleOrNull()?.toInt() ?: default
        else -> default
    }
}

