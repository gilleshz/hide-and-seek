package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.ChangeAccountPasswordRequest
import fr.gshz.hideandseek.domain.repository.AccountRepository
import javax.inject.Inject

class AccountRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
) : AccountRepository {

    override suspend fun changePassword(name: String, currentPassword: String, newPassword: String) {
        api.changeAccountPassword(
            url = urlFor("/api/account/password"),
            body = ChangeAccountPasswordRequest(
                name = name,
                currentPassword = currentPassword,
                newPassword = newPassword,
            ),
        )
    }

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path
}
