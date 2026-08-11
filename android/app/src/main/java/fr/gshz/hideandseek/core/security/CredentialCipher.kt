package fr.gshz.hideandseek.core.security

/**
 * Encrypts the player's join password at rest. The password is the only secret in the stored
 * credential; the display name and game uuid are public information anyway.
 */
interface CredentialCipher {
    /** Returns base64(iv + ciphertext), or null when the keystore is unavailable. */
    fun encrypt(plaintext: String): String?

    /** Returns the plaintext, or null on ANY failure (key lost, tamper, corrupt data). */
    fun decrypt(encoded: String): String?
}
