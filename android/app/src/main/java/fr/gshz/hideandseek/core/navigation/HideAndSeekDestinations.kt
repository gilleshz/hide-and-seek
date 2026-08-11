package fr.gshz.hideandseek.core.navigation

import android.net.Uri

object HideAndSeekDestinations {
    const val CONNECT_ERROR_KEY_ARG = "errorKey"
    const val CONNECT = "connect?$CONNECT_ERROR_KEY_ARG={$CONNECT_ERROR_KEY_ARG}"
    const val SCANNER = "scanner"
    const val HOME = "home"
    const val SETTINGS = "settings"
    const val CREATE_GAME = "createGame"
    const val JOIN_GAME_CODE_ARG = "code"
    const val JOIN_GAME_ERROR_KEY_ARG = "errorKey"
    const val JOIN_GAME =
        "joinGame?$JOIN_GAME_CODE_ARG={$JOIN_GAME_CODE_ARG}" +
            "&$JOIN_GAME_ERROR_KEY_ARG={$JOIN_GAME_ERROR_KEY_ARG}"
    const val LOBBY_ARG = "gameUuid"
    const val LOBBY = "lobby/{$LOBBY_ARG}"
    const val MAP_ARG = "gameUuid"
    const val MAP = "map/{$MAP_ARG}"
    const val CHAT_ARG = "gameUuid"
    const val CHAT = "chat/{$CHAT_ARG}"

    fun connectRoute(errorKey: String? = null): String {
        val args = buildList {
            if (!errorKey.isNullOrBlank()) add("$CONNECT_ERROR_KEY_ARG=${encode(errorKey)}")
        }
        return if (args.isEmpty()) "connect" else "connect?" + args.joinToString("&")
    }

    fun joinGameRoute(code: String? = null, errorKey: String? = null): String {
        val args = buildList {
            if (!code.isNullOrBlank()) add("$JOIN_GAME_CODE_ARG=${encode(code)}")
            if (!errorKey.isNullOrBlank()) add("$JOIN_GAME_ERROR_KEY_ARG=${encode(errorKey)}")
        }
        return if (args.isEmpty()) "joinGame" else "joinGame?" + args.joinToString("&")
    }

    // Uri.getQueryParameters splits on '&', stops at '#' and maps '+' to a space, so raw strings must be encoded here.
    private fun encode(value: String): String = Uri.encode(value, ALLOWED_QUERY_CHARS)

    private const val ALLOWED_QUERY_CHARS = "-._"

    fun lobbyRoute(gameUuid: String) = "lobby/$gameUuid"
    fun mapRoute(gameUuid: String) = "map/$gameUuid"
    fun chatRoute(gameUuid: String) = "chat/$gameUuid"
}
