package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class TransitLineDiscoveryRequest(
    val areas: List<AreaRef>,
    val routeTypes: List<String>? = null,
)
