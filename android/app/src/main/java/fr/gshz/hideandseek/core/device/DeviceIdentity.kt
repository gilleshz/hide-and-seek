package fr.gshz.hideandseek.core.device

import android.content.Context
import android.provider.Settings
import dagger.hilt.android.qualifiers.ApplicationContext
import java.nio.charset.StandardCharsets
import java.security.MessageDigest
import java.util.UUID
import javax.inject.Inject
import javax.inject.Singleton

internal fun deviceKeyFrom(seed: String): String =
    MessageDigest.getInstance("SHA-256")
        .digest(seed.toByteArray(StandardCharsets.UTF_8))
        .joinToString(separator = "") { "%02x".format(it) }

/**
 * Null and blank Android IDs are equally unusable: hashing an empty string would give every such
 * device the same public credential. The fallback is a random per-install seed.
 */
internal fun credentialSeed(androidId: String?, fallback: String): String =
    if (androidId.isNullOrBlank()) fallback else androidId

interface DeviceIdentity {
    val key: String
}

@Singleton
class AndroidDeviceIdentity @Inject constructor(
    @ApplicationContext private val context: Context,
) : DeviceIdentity {

    override val key: String by lazy {
        val androidId = Settings.Secure.getString(
            context.contentResolver,
            Settings.Secure.ANDROID_ID,
        )
        deviceKeyFrom(credentialSeed(androidId, fallbackSeed()))
    }

    private fun fallbackSeed(): String {
        val prefs = context.getSharedPreferences(FALLBACK_PREFS, Context.MODE_PRIVATE)
        prefs.getString(FALLBACK_KEY, null)?.let { return it }
        val seed = UUID.randomUUID().toString()
        prefs.edit().putString(FALLBACK_KEY, seed).commit()

        return seed
    }

    private companion object {
        const val FALLBACK_PREFS = "device_identity"
        const val FALLBACK_KEY = "fallback_seed"
    }
}
