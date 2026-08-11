package fr.gshz.hideandseek.feature.joingame

import fr.gshz.hideandseek.core.model.ErrorType

data class JoinGameUiState(
    val gameKey: String = "",
    val isLoading: Boolean = false,
    val error: ErrorType? = null,
    val errorDetail: String? = null,
    val errorKey: String? = null,
    val errorArgs: Map<String, String>? = null,
    val joinedGameUuid: String? = null,
    /** The connection has no account credential to join with: the user must set it up on connect. */
    val needsAccount: Boolean = false,
    val needAccountErrorKey: String? = null,
)
