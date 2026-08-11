package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.SessionStore
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.repository.SessionRepository
import javax.inject.Inject
import kotlinx.coroutines.flow.Flow

class SessionRepositoryImpl @Inject constructor(
    private val sessionStore: SessionStore,
) : SessionRepository {

    override fun observeSession(): Flow<PlayerSession?> = sessionStore.session

    override suspend fun currentSession(): PlayerSession? = sessionStore.current()

    override suspend fun saveSession(session: PlayerSession) = sessionStore.save(session)

    override suspend fun updateSide(side: Side, mercureToken: String, topics: List<String>) =
        sessionStore.updateSide(side.wireValue, mercureToken, topics)

    override suspend fun updateRoundUuid(roundUuid: String) = sessionStore.updateRoundUuid(roundUuid)

    override suspend fun clearSide() = sessionStore.clearSide()

    override suspend fun clear() = sessionStore.clear()

    override suspend fun updateMercureToken(mercureToken: String, topics: List<String>) =
        sessionStore.updateMercureToken(mercureToken, topics)

    override suspend fun saveMapStyle(style: String) = sessionStore.saveMapStyle(style)

    override fun observeMapStyle(): Flow<String?> = sessionStore.observeMapStyle()
}
