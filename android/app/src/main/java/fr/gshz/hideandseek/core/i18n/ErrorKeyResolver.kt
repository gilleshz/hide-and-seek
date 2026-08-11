package fr.gshz.hideandseek.core.i18n

import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource
import fr.gshz.hideandseek.R

private val ERROR_KEY_MAP: Map<String, Int> = mapOf(
    "question.seeker_only" to R.string.error_seeker_only,
    "question.hider_only" to R.string.error_hider_only,
    "question.out_of_turn" to R.string.error_out_of_turn,
    "question.game_not_active" to R.string.error_game_not_active,
    "question.round_not_round" to R.string.error_round_not_active,
    "question.category_disabled" to R.string.error_category_disabled,
    "question.already_asked" to R.string.error_already_asked,
    "question.all_questions_asked" to R.string.error_all_questions_asked,
    "question.powerup_blocked" to R.string.error_powerup_blocked,
    // Hiding is a team side: any of these means a teammate or the deadline got there first.
    "question.no_longer_open" to R.string.error_question_no_longer_open,
    "question.already_revealed" to R.string.error_question_already_answered,
    "question.reveal_only_open" to R.string.error_question_no_longer_open,
    "question.thermometer_already_complete" to R.string.error_thermometer_already_complete,
    "round.already_active" to R.string.error_round_already_active,
    "round.not_active" to R.string.error_round_not_active,
    "round.not_hider" to R.string.error_not_hider,
    "round.not_seeker" to R.string.error_not_seeker,
    "round.cannot_end" to R.string.error_cannot_end_round,
    "game.not_found" to R.string.error_not_found,
    "game.full" to R.string.error_game_full,
    "game.already_started" to R.string.error_game_already_started,
    "game.name_taken" to R.string.error_name_taken,
    "game.invalid_code" to R.string.error_invalid_code,
    "game.too_many_players" to R.string.error_too_many_players,
    "game.transit_tiles_failed" to R.string.error_transit_tiles_failed,
    "game.boundary_too_large" to R.string.error_boundary_too_large,
    "game.no_area_geometry" to R.string.error_no_area_geometry,
    "zone.invalid" to R.string.error_invalid_zone,
    "zone.too_small" to R.string.error_zone_too_small,
    "zone.too_large" to R.string.error_zone_too_large,
    "zone.outside_boundary" to R.string.error_zone_outside_boundary,
    "zone.already_set" to R.string.error_zone_already_set,
    "hiding_zone.card_required" to R.string.error_zone_card_required,
    "zone_card.not_seeking" to R.string.error_zone_card_not_seeking,
    "zone_card.endgame" to R.string.error_zone_card_endgame,
    "zone_card.no_zone" to R.string.error_zone_card_no_zone,
    "zone_card.unknown" to R.string.error_zone_card_unknown,
    "time_trap.not_found" to R.string.error_time_trap_not_found,
    "time_trap.not_hider" to R.string.error_not_hider,
    "time_trap.not_seeker" to R.string.error_not_seeker,
    "time_trap.not_seeking" to R.string.error_time_trap_not_seeking,
    "time_trap.no_station" to R.string.error_time_trap_no_station,
    "time_trap.limit_reached" to R.string.error_time_trap_limit_reached,
    "time_trap.not_pending" to R.string.error_time_trap_not_pending,
    "question.seekers_frozen" to R.string.error_seekers_frozen,
    "question.hiding_period" to R.string.question_hiding_period,
    "question.round_not_seeking" to R.string.error_round_not_taking_questions,
    "connection.not_connected" to R.string.error_not_connected,
    "connection.already_connected" to R.string.error_already_connected,
    // Identity 401s all map to the re-join flow; the inline text is a hint before navigation takes over.
    "identity.token_missing" to R.string.error_session_expired,
    "identity.token_invalid" to R.string.error_session_expired,
    "identity.player_not_found" to R.string.error_session_expired,
    "identity.player_left" to R.string.error_session_expired,
    "join.password_required" to R.string.error_join_password_required,
    "join.password_invalid" to R.string.error_join_password_invalid,
    "account.password_invalid" to R.string.settings_account_wrong_password,
    "account.name_taken" to R.string.error_account_name_taken,
    "settings.account_password_short" to R.string.settings_account_short_password,
    "connect.server_too_old" to R.string.error_server_too_old,
    "rate_limit.exceeded" to R.string.error_rate_limit,
)

/**
 * Maps backend [errorKey] values to their Android string resource IDs.
 * Returns null for keys without a dedicated string (falls back to [ErrorType.message]).
 */
@Suppress("ReturnCount")
@Composable
fun resolveError(errorKey: String?, errorArgs: Map<String, String>?): String? {
    val key = errorKey ?: return null
    val resId = ERROR_KEY_MAP[key] ?: return null
    val ordered = errorArgs?.let { orderedErrorArgs(key, it) } ?: emptyArray()
    @Suppress("detekt:SpreadOperator")
    return stringResource(resId, *ordered)
}

@Suppress("ReturnCount")
private fun orderedErrorArgs(key: String, args: Map<String, String>): Array<Any> = when (key) {
    // Keys with positional args, order them to match %1$s, %2$s in the string
    "game.name_taken" -> listOfNotNull(args["name"]).toTypedArray()
    else -> emptyArray()
}
