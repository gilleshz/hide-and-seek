package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.core.model.AccountCredential
import fr.gshz.hideandseek.core.model.ConnectionConfig
import kotlinx.coroutines.flow.Flow

interface ConnectionRepository {
    fun observeConnection(): Flow<ConnectionConfig?>
    suspend fun connect(config: ConnectionConfig)
    suspend fun disconnect()
    suspend fun saveAccount(credential: AccountCredential)
    suspend fun accountCredential(): AccountCredential?
}
