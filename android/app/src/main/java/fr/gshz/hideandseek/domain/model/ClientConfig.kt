package fr.gshz.hideandseek.domain.model

data class SiriProfileOption(
    val key: String,
    val label: String,
)

data class ClientConfig(
    val stadiaApiKey: String?,
    val thunderforestApiKey: String?,
    val maptilerApiKey: String?,
    val mapStyleAvailable: Boolean,
    val availableStyles: List<String>,
    val siriProfiles: List<SiriProfileOption> = emptyList(),
)
