@file:Suppress("MatchingDeclarationName")

package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class ChangeAccountPasswordRequest(
    val name: String,
    val currentPassword: String,
    val newPassword: String,
)
