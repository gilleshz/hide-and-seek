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
    /** The connection has no player identity yet: the join asks for a display name before creating it. */
    val needsDisplayName: Boolean = false,
    val displayName: String = "",
)
