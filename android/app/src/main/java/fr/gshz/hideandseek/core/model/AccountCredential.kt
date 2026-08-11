package fr.gshz.hideandseek.core.model

/**
 * The account identity (name + password) bound to a server. Persisted per-connection so the
 * identity follows the server, not the game session. Only the password is stored encrypted.
 */
data class AccountCredential(
    val name: String,
    val password: String,
)
