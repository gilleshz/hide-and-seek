package fr.gshz.hideandseek.core.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.core.stringSetPreferencesKey
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.di.SessionDataStore
import javax.inject.Inject
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

class SessionStore @Inject constructor(
    @SessionDataStore private val dataStore: DataStore<Preferences>,
) {
    private object Keys {
        val GAME_UUID = stringPreferencesKey("game_uuid")
        val ROUND_UUID = stringPreferencesKey("round_uuid")
        val PLAYER_UUID = stringPreferencesKey("player_uuid")
        val DISPLAY_NAME = stringPreferencesKey("display_name")
        val MERCURE_TOKEN = stringPreferencesKey("mercure_token")
        val SIDE = stringPreferencesKey("side")
        val MAP_STYLE = stringPreferencesKey("map_style")
        val TOPICS = stringSetPreferencesKey("topics")
    }

    val session: Flow<PlayerSession?> = dataStore.data.map { prefs ->
        val gameUuid = prefs[Keys.GAME_UUID] ?: return@map null
        val roundUuid = prefs[Keys.ROUND_UUID] ?: return@map null
        val playerUuid = prefs[Keys.PLAYER_UUID] ?: return@map null
        val displayName = prefs[Keys.DISPLAY_NAME] ?: return@map null
        val mercureToken = prefs[Keys.MERCURE_TOKEN] ?: return@map null
        val topics = prefs[Keys.TOPICS]?.toList() ?: emptyList()
        PlayerSession(
            gameUuid,
            roundUuid,
            playerUuid,
            displayName,
            mercureToken,
            prefs[Keys.SIDE],
            topics,
        )
    }

    suspend fun current(): PlayerSession? = session.first()

    suspend fun save(session: PlayerSession) {
        dataStore.edit { prefs ->
            prefs[Keys.GAME_UUID] = session.gameUuid
            prefs[Keys.ROUND_UUID] = session.roundUuid
            prefs[Keys.PLAYER_UUID] = session.playerUuid
            prefs[Keys.DISPLAY_NAME] = session.displayName
            prefs[Keys.MERCURE_TOKEN] = session.mercureToken
            prefs[Keys.TOPICS] = session.topics.toSet()
            if (session.side != null) {
                prefs[Keys.SIDE] = session.side
            } else {
                prefs.remove(Keys.SIDE)
            }
        }
    }

    suspend fun updateSide(side: String, mercureToken: String, topics: List<String>) {
        dataStore.edit { prefs ->
            prefs[Keys.SIDE] = side
            prefs[Keys.MERCURE_TOKEN] = mercureToken
            prefs[Keys.TOPICS] = topics.toSet()
        }
    }

    suspend fun updateRoundUuid(roundUuid: String) {
        dataStore.edit { prefs -> prefs[Keys.ROUND_UUID] = roundUuid }
    }

    suspend fun clearSide() {
        dataStore.edit { prefs ->
            prefs.remove(Keys.SIDE)
            prefs[Keys.TOPICS] = prefs[Keys.TOPICS].orEmpty()
                .filterNotTo(mutableSetOf()) { it.endsWith(PlayerSession.LOCATION_TOPIC_SUFFIX) }
        }
    }

    /** Wipes the active session; the identity now lives in the connection store. */
    suspend fun clear() {
        dataStore.edit { it.clear() }
    }

    suspend fun updateMercureToken(mercureToken: String, topics: List<String>) {
        dataStore.edit { prefs ->
            prefs[Keys.MERCURE_TOKEN] = mercureToken
            prefs[Keys.TOPICS] = topics.toSet()
        }
    }

    suspend fun saveMapStyle(style: String) {
        dataStore.edit { prefs -> prefs[Keys.MAP_STYLE] = style }
    }

    fun observeMapStyle(): Flow<String?> = dataStore.data.map { prefs -> prefs[Keys.MAP_STYLE] }
}
