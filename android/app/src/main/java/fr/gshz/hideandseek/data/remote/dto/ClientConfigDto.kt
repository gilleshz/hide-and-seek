package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class ClientConfigDto(
    val stadiaApiKey: String? = null,
    val thunderforestApiKey: String? = null,
    val maptilerApiKey: String? = null,
    val mapStyleAvailable: Boolean = false,
    val availableStyles: List<String> = emptyList(),
)
