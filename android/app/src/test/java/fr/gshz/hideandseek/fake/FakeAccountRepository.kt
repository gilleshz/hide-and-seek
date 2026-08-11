package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.repository.AccountRepository

class FakeAccountRepository : AccountRepository {
    var changePasswordResult: Result<Unit> = Result.success(Unit)
    val changePasswordCalls = mutableListOf<Triple<String, String, String>>()

    override suspend fun changePassword(name: String, currentPassword: String, newPassword: String) {
        changePasswordCalls += Triple(name, currentPassword, newPassword)
        changePasswordResult.getOrThrow()
    }
}
