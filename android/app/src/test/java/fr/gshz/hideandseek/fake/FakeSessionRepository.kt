package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.repository.SessionRepository
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow

class FakeSessionRepository : SessionRepository {
    private val state = MutableStateFlow<PlayerSession?>(null)
    private var silentState: PlayerSession? = null

    var updatedSide: Side? = null
        private set
    var updatedMercureToken: String? = null
        private set
    var updatedTopics: List<String>? = null
        private set
    var updatedRoundUuid: String? = null
        private set
    var sideCleared = false
        private set
    var refreshedMercureToken: String? = null
        private set
    var refreshedTopics: List<String>? = null
        private set

    fun seed(session: PlayerSession) {
        state.value = session
        silentState = null
    }

    /**
     * Applies a change that currentSession() sees immediately but the session flow has not delivered
     * yet: the race window the TokenRefresher's double re-read guards against.
     */
    fun mutateSession(mutator: (PlayerSession) -> PlayerSession) {
        silentState = (silentState ?: state.value)?.let(mutator)
    }

    override fun observeSession(): Flow<PlayerSession?> = state

    override suspend fun currentSession(): PlayerSession? = silentState ?: state.value

    override suspend fun saveSession(session: PlayerSession) {
        state.value = session
        silentState = null
    }

    override suspend fun updateSide(side: Side, mercureToken: String, topics: List<String>) {
        updatedSide = side
        updatedMercureToken = mercureToken
        updatedTopics = topics
        state.value = state.value?.copy(side = side.wireValue, mercureToken = mercureToken, topics = topics)
        silentState = null
    }

    override suspend fun updateRoundUuid(roundUuid: String) {
        updatedRoundUuid = roundUuid
        state.value = state.value?.copy(roundUuid = roundUuid)
        silentState = null
    }

    override suspend fun clearSide() {
        sideCleared = true
        state.value = state.value?.let { it.copy(side = null, topics = it.topicsWithoutLocations()) }
        silentState = null
    }

    override suspend fun clear() {
        state.value = null
        silentState = null
    }

    override suspend fun updateMercureToken(mercureToken: String, topics: List<String>) {
        refreshedMercureToken = mercureToken
        refreshedTopics = topics
        state.value = state.value?.copy(mercureToken = mercureToken, topics = topics)
        silentState = null
    }

    private val mapStyle = MutableStateFlow<String?>(null)

    override suspend fun saveMapStyle(style: String) {
        mapStyle.value = style
    }

    override fun observeMapStyle(): Flow<String?> = mapStyle
}
