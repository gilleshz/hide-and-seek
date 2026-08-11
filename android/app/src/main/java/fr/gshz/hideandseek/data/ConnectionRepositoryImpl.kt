package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.AccountCredential
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.domain.repository.ConnectionRepository
import javax.inject.Inject
import kotlinx.coroutines.flow.Flow

class ConnectionRepositoryImpl @Inject constructor(
    private val connectionStore: ConnectionStore,
) : ConnectionRepository {

    override fun observeConnection(): Flow<ConnectionConfig?> = connectionStore.connectionConfig

    override suspend fun connect(config: ConnectionConfig) = connectionStore.save(config)

    override suspend fun disconnect() = connectionStore.clear()

    override suspend fun saveAccount(credential: AccountCredential) = connectionStore.save(credential)

    override suspend fun accountCredential(): AccountCredential? = connectionStore.currentAccount()
}
