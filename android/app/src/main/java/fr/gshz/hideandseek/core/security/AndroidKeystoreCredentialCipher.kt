package fr.gshz.hideandseek.core.security

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * AES/GCM credential cipher backed by the Android Keystore, keyed by [alias] so each secret family
 * keeps its own keystore key. The key lives in the keystore, not in the app sandbox, so it survives
 * app data copies but is unusable on other devices. Hardware-backed where the device provides it;
 * the keystore decides transparently. Ciphertext and plaintext are never logged.
 */
class AndroidKeystoreCredentialCipher(private val alias: String) : CredentialCipher {

    private val keyStore: KeyStore? = try {
        KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
    } catch (_: Exception) {
        null
    }

    override fun encrypt(plaintext: String): String? {
        return try {
            val cipher = Cipher.getInstance(TRANSFORMATION)
            val key = key() ?: return null
            cipher.init(Cipher.ENCRYPT_MODE, key)
            val blob = cipher.iv + cipher.doFinal(plaintext.toByteArray(Charsets.UTF_8))
            Base64.encodeToString(blob, Base64.NO_WRAP)
        } catch (_: Exception) {
            null
        }
    }

    override fun decrypt(encoded: String): String? {
        return try {
            val blob = Base64.decode(encoded, Base64.NO_WRAP)
            if (blob.size <= IV_LENGTH_BYTES) {
                null
            } else {
                val iv = blob.copyOfRange(0, IV_LENGTH_BYTES)
                val ciphertext = blob.copyOfRange(IV_LENGTH_BYTES, blob.size)
                val cipher = Cipher.getInstance(TRANSFORMATION)
                val key = key() ?: return null
                cipher.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(TAG_LENGTH_BITS, iv))
                String(cipher.doFinal(ciphertext), Charsets.UTF_8)
            }
        } catch (_: Exception) {
            // Any failure (key invalidated, tampered blob, wrong key) means "no stored credential".
            null
        }
    }

    private fun key(): SecretKey? {
        val store = keyStore ?: return null
        return store.getKey(alias, null) as? SecretKey ?: generateKey()
    }

    private fun generateKey(): SecretKey {
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE)
        generator.init(
            KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(KEY_SIZE_BITS)
                .build(),
        )
        return generator.generateKey()
    }

    private companion object {
        const val ANDROID_KEYSTORE = "AndroidKeyStore"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val IV_LENGTH_BYTES = 12
        const val TAG_LENGTH_BITS = 128
        const val KEY_SIZE_BITS = 256
    }
}
