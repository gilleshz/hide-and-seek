package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.Profile

interface AvatarRepository {
    suspend fun bootstrap(displayName: String): Profile
    suspend fun rename(displayName: String): Profile
    suspend fun getProfile(): Profile
    suspend fun uploadAvatar(jpeg: ByteArray): Profile
    suspend fun avatarUrl(avatarRef: String): String
    suspend fun removeAvatar()
}
