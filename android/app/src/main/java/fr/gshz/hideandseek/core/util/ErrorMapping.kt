package fr.gshz.hideandseek.core.util

import fr.gshz.hideandseek.core.model.ErrorType
import java.io.IOException
import java.net.HttpURLConnection
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import retrofit2.HttpException

fun HttpException.toErrorType(): ErrorType = when (code()) {
    HttpURLConnection.HTTP_UNAUTHORIZED -> ErrorType.Unauthorized
    HttpURLConnection.HTTP_NOT_FOUND -> ErrorType.NotFound
    HttpURLConnection.HTTP_BAD_REQUEST -> ErrorType.Validation
    else -> ErrorType.Unknown
}

/**
 * True when the server rejected the subscriber token as an identity: the token is missing, invalid,
 * expired, names a deleted player, or its player left the game. The API-key 401 carries no errorKey,
 * so the identity.* prefix is what separates "re-join" from "wrong API key".
 */
fun HttpException.isSessionExpired(): Boolean =
    code() in SESSION_EXPIRED_CODE_RANGE && serverErrorKey()?.startsWith("identity.") == true

private val SESSION_EXPIRED_CODE_RANGE = 401..403

// A kick must not be silently undone by the stored credential; only token expiry auto-rejoins.
const val ERROR_KEY_PLAYER_LEFT = "identity.player_left"

// The account the server has on file rejects the join: the user must re-enter it on the connect screen.
const val ERROR_KEY_JOIN_PASSWORD_REQUIRED = "join.password_required"
const val ERROR_KEY_JOIN_PASSWORD_INVALID = "join.password_invalid"

// The backend emits RFC 7807 problems; the human-readable reason is "detail" ("hydra:description" under Hydra).
private val PROBLEM_DETAIL_KEYS = listOf("detail", "hydra:description", "description")

/**
 * Peeks the error body instead of reading it: string() closes the stream, so the second of
 * serverErrorKey()/serverErrorArgs() on the same exception used to see nothing.
 */
private fun HttpException.problemJson(): JsonObject? = try {
    response()?.errorBody()?.source()?.peek()?.readUtf8()
        ?.let { Json.parseToJsonElement(it) as? JsonObject }
} catch (_: IOException) {
    null
} catch (_: IllegalArgumentException) {
    null
}

fun HttpException.serverDetail(): String? = problemJson()
    ?.let { obj -> PROBLEM_DETAIL_KEYS.firstNotNullOfOrNull { (obj[it] as? JsonPrimitive)?.contentOrNull } }
    ?.takeIf { it.isNotBlank() }

fun HttpException.serverErrorKey(): String? =
    (problemJson()?.get("errorKey") as? JsonPrimitive)?.contentOrNull

fun HttpException.serverErrorArgs(): Map<String, String>? {
    val argsObj = problemJson()?.get("errorArgs") as? JsonObject ?: return null

    return argsObj.mapValues { (_, v) -> (v as? JsonPrimitive)?.content ?: v.toString() }
}
