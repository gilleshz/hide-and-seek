package fr.gshz.hideandseek.feature.connect

import fr.gshz.hideandseek.core.model.ErrorType

data class ConnectUiState(
    val apiUrl: String = "",
    val apiKey: String = "",
    val displayName: String = "",
    val passwordInput: String = "",
    val isConnecting: Boolean = false,
    val isPlainHttp: Boolean = false,
    val error: ErrorType? = null,
    val errorKey: String? = null,
    val connected: Boolean = false,
    val scannedGameCode: String? = null,
)
