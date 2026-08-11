package fr.gshz.hideandseek.domain.repository

interface AccountRepository {
    suspend fun changePassword(name: String, currentPassword: String, newPassword: String)
}
