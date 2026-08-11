package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.core.security.CredentialCipher

/** In-memory credential cipher for tests; [failureMode] simulates a lost or invalid keystore key. */
class FakeCredentialCipher : CredentialCipher {

    var failureMode = false
    private val store = mutableMapOf<String, String>()

    override fun encrypt(plaintext: String): String? {
        if (failureMode) return null
        val encoded = "enc:$plaintext"
        store[encoded] = plaintext
        return encoded
    }

    override fun decrypt(encoded: String): String? {
        if (failureMode) return null
        return store[encoded]
    }
}
