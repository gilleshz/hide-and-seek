package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.domain.model.Side
import kotlinx.coroutines.flow.Flow

interface SessionRepository {
    fun observeSession(): Flow<PlayerSession?>
    suspend fun currentSession(): PlayerSession?
    suspend fun saveSession(session: PlayerSession)
    suspend fun updateSide(side: Side, mercureToken: String, topics: List<String>)
    suspend fun updateRoundUuid(roundUuid: String)
    suspend fun clearSide()
    suspend fun clear()
    suspend fun updateMercureToken(mercureToken: String, topics: List<String>)
    suspend fun saveMapStyle(style: String)
    fun observeMapStyle(): Flow<String?>
}
