package fr.gshz.hideandseek.core.security

import fr.gshz.hideandseek.core.data.ConnectionStore
import java.security.KeyStore
import javax.inject.Inject
import javax.inject.Singleton

/**
 * The device-bound identity replaced the account password. Upgraded installs still carry the old
 * encrypted password in the connection store and its Keystore key; remove both on first launch.
 */
@Singleton
class LegacyCredentialCleanup @Inject constructor(
    private val connectionStore: ConnectionStore,
) {
    suspend fun run() {
        connectionStore.clearLegacyPassword()
        runCatching {
            KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }.deleteEntry(LEGACY_ALIAS)
        }
    }

    private companion object {
        const val ANDROID_KEYSTORE = "AndroidKeyStore"
        const val LEGACY_ALIAS = "hideandseek-account-password"
    }
}
