package fr.gshz.hideandseek.core.security

/**
 * Encrypts credentials at rest. The connection API key and the chat image key flow through it;
 * the player identity is derived from the device and stores no secret.
 */
interface CredentialCipher {
    /** Returns base64(iv + ciphertext), or null when the keystore is unavailable. */
    fun encrypt(plaintext: String): String?

    /** Returns the plaintext, or null on ANY failure (key lost, tamper, corrupt data). */
    fun decrypt(encoded: String): String?
}
