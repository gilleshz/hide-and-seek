package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class CancelQuestionRequest(val askerPlayerUuid: String = "")
