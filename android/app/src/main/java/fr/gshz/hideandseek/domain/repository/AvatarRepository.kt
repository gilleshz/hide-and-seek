package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.Profile

interface AvatarRepository {
    suspend fun getProfile(): Profile
    suspend fun uploadAvatar(jpeg: ByteArray): String
    suspend fun avatarUrl(avatarRef: String): String
    suspend fun removeAvatar()
}
