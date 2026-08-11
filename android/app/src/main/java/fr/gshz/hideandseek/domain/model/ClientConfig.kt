package fr.gshz.hideandseek.domain.model

data class ClientConfig(
    val stadiaApiKey: String?,
    val thunderforestApiKey: String?,
    val maptilerApiKey: String?,
    val mapStyleAvailable: Boolean,
    val availableStyles: List<String>,
)
