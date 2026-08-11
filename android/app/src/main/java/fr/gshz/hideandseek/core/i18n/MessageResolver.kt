package fr.gshz.hideandseek.core.i18n

import android.content.Context
import androidx.annotation.StringRes
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.formatDurationWords

/**
 * Maps backend translation keys (dot.separated) to Android string resources in the current locale.
 * The Context overload lets notifications reuse the mapping so chat bubbles and notifications never
 * drift. Every key maps to the string resource named after it with dots as underscores;
 * MessageResolverTest pins the whole table and the convention, so a key without a matching resource
 * fails loudly instead of silently rendering the fallback.
 */
@Composable
fun resolveMessage(bodyKey: String?, bodyArgs: Map<String, String>?, fallback: String?): String =
    resolveMessage(LocalContext.current, bodyKey, bodyArgs, fallback)

@Suppress("SpreadOperator")
fun resolveMessage(
    context: Context,
    bodyKey: String?,
    bodyArgs: Map<String, String>?,
    fallback: String?,
): String {
    if (bodyKey == null) return fallback ?: ""
    val resId = messageKeyToResId(bodyKey)
    val args = bodyArgs ?: emptyMap()
    return when {
        resId == null -> fallback ?: bodyKey
        args.isEmpty() -> context.getString(resId)
        else -> formatMessage(
            { id, formatArgs -> context.getString(id, *formatArgs) },
            resId,
            orderedArgsWithContext(context, bodyKey, args),
        )
    }
}

/**
 * The format-args assembly, with the getString call injected so JVM tests can pin the exact vararg
 * array: without the spread into Object... every formatted message would render garbage.
 */
internal fun formatMessage(
    getString: (resId: Int, formatArgs: Array<out Any>) -> String,
    resId: Int,
    formatArgs: List<Any>,
): String = getString(resId, formatArgs.toTypedArray())

internal fun orderedArgs(
    key: String,
    args: Map<String, String>,
    durationText: (seconds: String?, legacyClock: String?) -> String,
): List<Any> =
    ARGS_BY_KEY[key].orEmpty().map { arg ->
        when (arg) {
            is PlainArg -> args[arg.name] ?: arg.default
            is DurationArg -> durationText(args[arg.name], args[arg.legacyName])
        }
    }

private fun orderedArgsWithContext(context: Context, key: String, args: Map<String, String>): List<Any> =
    orderedArgs(key, args) { seconds, legacyClock -> duration(context, seconds, legacyClock) }

@StringRes
internal fun messageKeyToResId(key: String): Int? = MESSAGE_KEY_TO_RES_ID[key]

private val MESSAGE_KEY_TO_RES_ID: Map<String, Int> = mapOf(
    "question.radar.ask" to R.string.question_radar_ask,
    "question.matching.ask_no_name.transit_station" to R.string.question_matching_ask_no_name_transit_station,
    "question.matching.ask_with_name.transit_station" to R.string.question_matching_ask_with_name_transit_station,
    "question.measuring.ask_no_name.transit_station" to R.string.question_measuring_ask_no_name_transit_station,
    "question.measuring.ask_with_feature_no_name.transit_station" to
        R.string.question_measuring_ask_with_feature_no_name_transit_station,
    "question.measuring.ask_with_name.transit_station" to R.string.question_measuring_ask_with_name_transit_station,
    "question.tentacles.ask.transit_station" to R.string.question_tentacles_ask_transit_station,
    "question.matching.ask_no_name.commercial_airport" to R.string.question_matching_ask_no_name_commercial_airport,
    "question.matching.ask_with_name.commercial_airport" to R.string.question_matching_ask_with_name_commercial_airport,
    "question.measuring.ask_no_name.commercial_airport" to R.string.question_measuring_ask_no_name_commercial_airport,
    "question.measuring.ask_with_feature_no_name.commercial_airport" to
        R.string.question_measuring_ask_with_feature_no_name_commercial_airport,
    "question.measuring.ask_with_name.commercial_airport" to
        R.string.question_measuring_ask_with_name_commercial_airport,
    "question.tentacles.ask.commercial_airport" to R.string.question_tentacles_ask_commercial_airport,
    "question.matching.ask_no_name.rail_station" to R.string.question_matching_ask_no_name_rail_station,
    "question.matching.ask_with_name.rail_station" to R.string.question_matching_ask_with_name_rail_station,
    "question.measuring.ask_no_name.rail_station" to R.string.question_measuring_ask_no_name_rail_station,
    "question.measuring.ask_with_feature_no_name.rail_station" to
        R.string.question_measuring_ask_with_feature_no_name_rail_station,
    "question.measuring.ask_with_name.rail_station" to R.string.question_measuring_ask_with_name_rail_station,
    "question.tentacles.ask.rail_station" to R.string.question_tentacles_ask_rail_station,
    "question.matching.ask_no_name.metro_line" to R.string.question_matching_ask_no_name_metro_line,
    "question.matching.ask_with_name.metro_line" to R.string.question_matching_ask_with_name_metro_line,
    "question.measuring.ask_no_name.metro_line" to R.string.question_measuring_ask_no_name_metro_line,
    "question.measuring.ask_with_feature_no_name.metro_line" to
        R.string.question_measuring_ask_with_feature_no_name_metro_line,
    "question.measuring.ask_with_name.metro_line" to R.string.question_measuring_ask_with_name_metro_line,
    "question.tentacles.ask.metro_line" to R.string.question_tentacles_ask_metro_line,
    "question.matching.ask_no_name.high_speed_rail_line" to R.string.question_matching_ask_no_name_high_speed_rail_line,
    "question.matching.ask_with_name.high_speed_rail_line" to
        R.string.question_matching_ask_with_name_high_speed_rail_line,
    "question.measuring.ask_no_name.high_speed_rail_line" to
        R.string.question_measuring_ask_no_name_high_speed_rail_line,
    "question.measuring.ask_with_feature_no_name.high_speed_rail_line" to
        R.string.question_measuring_ask_with_feature_no_name_high_speed_rail_line,
    "question.measuring.ask_with_name.high_speed_rail_line" to
        R.string.question_measuring_ask_with_name_high_speed_rail_line,
    "question.tentacles.ask.high_speed_rail_line" to R.string.question_tentacles_ask_high_speed_rail_line,
    "question.matching.ask_no_name.mountain" to R.string.question_matching_ask_no_name_mountain,
    "question.matching.ask_with_name.mountain" to R.string.question_matching_ask_with_name_mountain,
    "question.measuring.ask_no_name.mountain" to R.string.question_measuring_ask_no_name_mountain,
    "question.measuring.ask_with_feature_no_name.mountain" to
        R.string.question_measuring_ask_with_feature_no_name_mountain,
    "question.measuring.ask_with_name.mountain" to R.string.question_measuring_ask_with_name_mountain,
    "question.tentacles.ask.mountain" to R.string.question_tentacles_ask_mountain,
    "question.matching.ask_no_name.park" to R.string.question_matching_ask_no_name_park,
    "question.matching.ask_with_name.park" to R.string.question_matching_ask_with_name_park,
    "question.measuring.ask_no_name.park" to R.string.question_measuring_ask_no_name_park,
    "question.measuring.ask_with_feature_no_name.park" to R.string.question_measuring_ask_with_feature_no_name_park,
    "question.measuring.ask_with_name.park" to R.string.question_measuring_ask_with_name_park,
    "question.tentacles.ask.park" to R.string.question_tentacles_ask_park,
    "question.matching.ask_no_name.body_of_water" to R.string.question_matching_ask_no_name_body_of_water,
    "question.matching.ask_with_name.body_of_water" to R.string.question_matching_ask_with_name_body_of_water,
    "question.measuring.ask_no_name.body_of_water" to R.string.question_measuring_ask_no_name_body_of_water,
    "question.measuring.ask_with_feature_no_name.body_of_water" to
        R.string.question_measuring_ask_with_feature_no_name_body_of_water,
    "question.measuring.ask_with_name.body_of_water" to R.string.question_measuring_ask_with_name_body_of_water,
    "question.tentacles.ask.body_of_water" to R.string.question_tentacles_ask_body_of_water,
    "question.matching.ask_no_name.coastline" to R.string.question_matching_ask_no_name_coastline,
    "question.matching.ask_with_name.coastline" to R.string.question_matching_ask_with_name_coastline,
    "question.measuring.ask_no_name.coastline" to R.string.question_measuring_ask_no_name_coastline,
    "question.measuring.ask_with_feature_no_name.coastline" to
        R.string.question_measuring_ask_with_feature_no_name_coastline,
    "question.measuring.ask_with_name.coastline" to R.string.question_measuring_ask_with_name_coastline,
    "question.tentacles.ask.coastline" to R.string.question_tentacles_ask_coastline,
    "question.measuring.ask_no_name.border_international" to
        R.string.question_measuring_ask_no_name_border_international,
    "question.measuring.ask_with_feature_no_name.border_international" to
        R.string.question_measuring_ask_with_feature_no_name_border_international,
    "question.measuring.ask_with_name.border_international" to
        R.string.question_measuring_ask_with_name_border_international,
    "question.measuring.ask_no_name.border_admin_1st" to R.string.question_measuring_ask_no_name_border_admin_1st,
    "question.measuring.ask_with_feature_no_name.border_admin_1st" to
        R.string.question_measuring_ask_with_feature_no_name_border_admin_1st,
    "question.measuring.ask_with_name.border_admin_1st" to R.string.question_measuring_ask_with_name_border_admin_1st,
    "question.measuring.ask_no_name.border_admin_2nd" to R.string.question_measuring_ask_no_name_border_admin_2nd,
    "question.measuring.ask_with_feature_no_name.border_admin_2nd" to
        R.string.question_measuring_ask_with_feature_no_name_border_admin_2nd,
    "question.measuring.ask_with_name.border_admin_2nd" to R.string.question_measuring_ask_with_name_border_admin_2nd,
    "question.matching.ask_no_name.admin_boundary_1st" to R.string.question_matching_ask_no_name_admin_boundary_1st,
    "question.matching.ask_with_name.admin_boundary_1st" to R.string.question_matching_ask_with_name_admin_boundary_1st,
    "question.measuring.ask_no_name.admin_boundary_1st" to R.string.question_measuring_ask_no_name_admin_boundary_1st,
    "question.measuring.ask_with_feature_no_name.admin_boundary_1st" to
        R.string.question_measuring_ask_with_feature_no_name_admin_boundary_1st,
    "question.measuring.ask_with_name.admin_boundary_1st" to
        R.string.question_measuring_ask_with_name_admin_boundary_1st,
    "question.tentacles.ask.admin_boundary_1st" to R.string.question_tentacles_ask_admin_boundary_1st,
    "question.matching.ask_no_name.admin_boundary_2nd" to R.string.question_matching_ask_no_name_admin_boundary_2nd,
    "question.matching.ask_with_name.admin_boundary_2nd" to R.string.question_matching_ask_with_name_admin_boundary_2nd,
    "question.matching.ask_no_name.admin_boundary_3rd" to R.string.question_matching_ask_no_name_admin_boundary_3rd,
    "question.matching.ask_with_name.admin_boundary_3rd" to R.string.question_matching_ask_with_name_admin_boundary_3rd,
    "question.matching.ask_no_name.admin_boundary_4th" to R.string.question_matching_ask_no_name_admin_boundary_4th,
    "question.matching.ask_with_name.admin_boundary_4th" to R.string.question_matching_ask_with_name_admin_boundary_4th,
    "question.measuring.ask_no_name.admin_boundary_2nd" to R.string.question_measuring_ask_no_name_admin_boundary_2nd,
    "question.measuring.ask_with_feature_no_name.admin_boundary_2nd" to
        R.string.question_measuring_ask_with_feature_no_name_admin_boundary_2nd,
    "question.measuring.ask_with_name.admin_boundary_2nd" to
        R.string.question_measuring_ask_with_name_admin_boundary_2nd,
    "question.tentacles.ask.admin_boundary_2nd" to R.string.question_tentacles_ask_admin_boundary_2nd,
    "question.matching.ask_no_name.hospital" to R.string.question_matching_ask_no_name_hospital,
    "question.matching.ask_with_name.hospital" to R.string.question_matching_ask_with_name_hospital,
    "question.measuring.ask_no_name.hospital" to R.string.question_measuring_ask_no_name_hospital,
    "question.measuring.ask_with_feature_no_name.hospital" to
        R.string.question_measuring_ask_with_feature_no_name_hospital,
    "question.measuring.ask_with_name.hospital" to R.string.question_measuring_ask_with_name_hospital,
    "question.tentacles.ask.hospital" to R.string.question_tentacles_ask_hospital,
    "question.matching.ask_no_name.library" to R.string.question_matching_ask_no_name_library,
    "question.matching.ask_with_name.library" to R.string.question_matching_ask_with_name_library,
    "question.measuring.ask_no_name.library" to R.string.question_measuring_ask_no_name_library,
    "question.measuring.ask_with_feature_no_name.library" to
        R.string.question_measuring_ask_with_feature_no_name_library,
    "question.measuring.ask_with_name.library" to R.string.question_measuring_ask_with_name_library,
    "question.tentacles.ask.library" to R.string.question_tentacles_ask_library,
    "question.matching.ask_no_name.museum" to R.string.question_matching_ask_no_name_museum,
    "question.matching.ask_with_name.museum" to R.string.question_matching_ask_with_name_museum,
    "question.measuring.ask_no_name.museum" to R.string.question_measuring_ask_no_name_museum,
    "question.measuring.ask_with_feature_no_name.museum" to R.string.question_measuring_ask_with_feature_no_name_museum,
    "question.measuring.ask_with_name.museum" to R.string.question_measuring_ask_with_name_museum,
    "question.tentacles.ask.museum" to R.string.question_tentacles_ask_museum,
    "question.matching.ask_no_name.movie_theater" to R.string.question_matching_ask_no_name_movie_theater,
    "question.matching.ask_with_name.movie_theater" to R.string.question_matching_ask_with_name_movie_theater,
    "question.measuring.ask_no_name.movie_theater" to R.string.question_measuring_ask_no_name_movie_theater,
    "question.measuring.ask_with_feature_no_name.movie_theater" to
        R.string.question_measuring_ask_with_feature_no_name_movie_theater,
    "question.measuring.ask_with_name.movie_theater" to R.string.question_measuring_ask_with_name_movie_theater,
    "question.tentacles.ask.movie_theater" to R.string.question_tentacles_ask_movie_theater,
    "question.matching.ask_no_name.zoo" to R.string.question_matching_ask_no_name_zoo,
    "question.matching.ask_with_name.zoo" to R.string.question_matching_ask_with_name_zoo,
    "question.measuring.ask_no_name.zoo" to R.string.question_measuring_ask_no_name_zoo,
    "question.measuring.ask_with_feature_no_name.zoo" to R.string.question_measuring_ask_with_feature_no_name_zoo,
    "question.measuring.ask_with_name.zoo" to R.string.question_measuring_ask_with_name_zoo,
    "question.tentacles.ask.zoo" to R.string.question_tentacles_ask_zoo,
    "question.matching.ask_no_name.aquarium" to R.string.question_matching_ask_no_name_aquarium,
    "question.matching.ask_with_name.aquarium" to R.string.question_matching_ask_with_name_aquarium,
    "question.measuring.ask_no_name.aquarium" to R.string.question_measuring_ask_no_name_aquarium,
    "question.measuring.ask_with_feature_no_name.aquarium" to
        R.string.question_measuring_ask_with_feature_no_name_aquarium,
    "question.measuring.ask_with_name.aquarium" to R.string.question_measuring_ask_with_name_aquarium,
    "question.tentacles.ask.aquarium" to R.string.question_tentacles_ask_aquarium,
    "question.matching.ask_no_name.amusement_park" to R.string.question_matching_ask_no_name_amusement_park,
    "question.matching.ask_with_name.amusement_park" to R.string.question_matching_ask_with_name_amusement_park,
    "question.measuring.ask_no_name.amusement_park" to R.string.question_measuring_ask_no_name_amusement_park,
    "question.measuring.ask_with_feature_no_name.amusement_park" to
        R.string.question_measuring_ask_with_feature_no_name_amusement_park,
    "question.measuring.ask_with_name.amusement_park" to R.string.question_measuring_ask_with_name_amusement_park,
    "question.tentacles.ask.amusement_park" to R.string.question_tentacles_ask_amusement_park,
    "question.matching.ask_no_name.golf_course" to R.string.question_matching_ask_no_name_golf_course,
    "question.matching.ask_with_name.golf_course" to R.string.question_matching_ask_with_name_golf_course,
    "question.measuring.ask_no_name.golf_course" to R.string.question_measuring_ask_no_name_golf_course,
    "question.measuring.ask_with_feature_no_name.golf_course" to
        R.string.question_measuring_ask_with_feature_no_name_golf_course,
    "question.measuring.ask_with_name.golf_course" to R.string.question_measuring_ask_with_name_golf_course,
    "question.tentacles.ask.golf_course" to R.string.question_tentacles_ask_golf_course,
    "question.matching.ask_no_name.consulate" to R.string.question_matching_ask_no_name_consulate,
    "question.matching.ask_with_name.consulate" to R.string.question_matching_ask_with_name_consulate,
    "question.measuring.ask_no_name.consulate" to R.string.question_measuring_ask_no_name_consulate,
    "question.measuring.ask_with_feature_no_name.consulate" to
        R.string.question_measuring_ask_with_feature_no_name_consulate,
    "question.measuring.ask_with_name.consulate" to R.string.question_measuring_ask_with_name_consulate,
    "question.tentacles.ask.consulate" to R.string.question_tentacles_ask_consulate,
    "question.photos.ask.tree" to R.string.question_photos_ask_tree,
    "question.photos.ask.sky" to R.string.question_photos_ask_sky,
    "question.photos.ask.selfie" to R.string.question_photos_ask_selfie,
    "question.photos.ask.widest_street" to R.string.question_photos_ask_widest_street,
    "question.photos.ask.tallest_structure_in_sightline" to R.string.question_photos_ask_tallest_structure_in_sightline,
    "question.photos.ask.building_visible_from_station" to R.string.question_photos_ask_building_visible_from_station,
    "question.photos.ask.tallest_building_from_station" to R.string.question_photos_ask_tallest_building_from_station,
    "question.photos.ask.trace_nearest_street" to R.string.question_photos_ask_trace_nearest_street,
    "question.photos.ask.two_buildings" to R.string.question_photos_ask_two_buildings,
    "question.photos.ask.restaurant_interior" to R.string.question_photos_ask_restaurant_interior,
    "question.photos.ask.train_platform" to R.string.question_photos_ask_train_platform,
    "question.photos.ask.park" to R.string.question_photos_ask_park,
    "question.photos.ask.grocery_store_aisle" to R.string.question_photos_ask_grocery_store_aisle,
    "question.photos.ask.place_of_worship" to R.string.question_photos_ask_place_of_worship,
    "question.photos.ask.streets_traced_metric" to R.string.question_photos_ask_streets_traced_metric,
    "question.photos.ask.streets_traced_imperial" to R.string.question_photos_ask_streets_traced_imperial,
    "question.photos.ask.tallest_mountain_from_station" to R.string.question_photos_ask_tallest_mountain_from_station,
    "question.photos.ask.biggest_body_of_water_in_zone" to R.string.question_photos_ask_biggest_body_of_water_in_zone,
    "question.photos.ask.five_buildings" to R.string.question_photos_ask_five_buildings,
    "question.thermometer.start" to R.string.question_thermometer_start,
    "question.thermometer.complete" to R.string.question_thermometer_complete,
    "question.answer.yes_within_range" to R.string.question_answer_yes_within_range,
    "question.answer.no_not_within_range" to R.string.question_answer_no_not_within_range,
    "question.answer.same" to R.string.question_answer_same,
    "question.answer.different" to R.string.question_answer_different,
    "question.answer.hotter" to R.string.question_answer_hotter,
    "question.answer.colder" to R.string.question_answer_colder,
    "question.answer.closer" to R.string.question_answer_closer,
    "question.answer.further" to R.string.question_answer_further,
    "question.answer.sea_level_closer" to R.string.question_answer_sea_level_closer,
    "question.answer.sea_level_further" to R.string.question_answer_sea_level_further,
    "question.measuring.ask.sea_level" to R.string.question_measuring_ask_sea_level,
    "question.answer.no_answer" to R.string.question_answer_no_answer,
    "question.answer.photo_in_chat" to R.string.question_answer_photo_in_chat,
    "question.answer.tentacles_answer" to R.string.question_answer_tentacles_answer,
    "question.matching.ask_transit_line" to R.string.question_matching_ask_transit_line,
    "question.matching.ask_station_name_length" to R.string.question_matching_ask_station_name_length,
    "question.answer.transit_line_yes" to R.string.question_answer_transit_line_yes,
    "question.answer.transit_line_no" to R.string.question_answer_transit_line_no,
    "system.player_joined" to R.string.system_player_joined,
    "system.player_left" to R.string.system_player_left,
    "system.player_removed" to R.string.system_player_removed,
    "system.player_rejoined" to R.string.system_player_rejoined,
    "system.question_cancelled" to R.string.system_question_cancelled,
    "system.question_vetoed" to R.string.system_question_vetoed,
    "system.question_randomized" to R.string.system_question_randomized,
    "round.hiding_time" to R.string.round_hiding_time,
    "round.hiding_time_with_bonus" to R.string.round_hiding_time_with_bonus,
    "zone.moved" to R.string.zone_moved,
    "zone.resized" to R.string.zone_resized,
    "zone.moved_and_resized" to R.string.zone_moved_and_resized,
    "zone.prosperous_home" to R.string.zone_prosperous_home,
    "zone.tiny_home" to R.string.zone_tiny_home,
    "zone.move_played" to R.string.zone_move_played,
    "zone.move_played_from" to R.string.zone_move_played_from,
    "round.move_period_over" to R.string.round_move_period_over,
    "trap.placed" to R.string.trap_placed,
    "trap.detected" to R.string.trap_detected,
    "trap.sprung" to R.string.trap_sprung,
    "trap.dismissed" to R.string.trap_dismissed,
)

private sealed interface MessageArg {
    val name: String
}

private data class PlainArg(override val name: String, val default: String = "") : MessageArg

private data class DurationArg(override val name: String, val legacyName: String) : MessageArg

private val FEATURE_ARG_NAMES: Map<String, List<MessageArg>> = mapOf(
    "matching.ask_with_name" to listOf(PlainArg("name")),
    "measuring.ask_with_feature_no_name" to listOf(PlainArg("distance")),
    "measuring.ask_with_name" to listOf(PlainArg("distance"), PlainArg("name")),
    "tentacles.ask" to listOf(PlainArg("range")),
)

private val BASE_FEATURE_TYPES: List<String> = listOf(
    "transit_station", "commercial_airport", "rail_station", "metro_line",
    "high_speed_rail_line", "mountain", "park", "body_of_water",
    "coastline", "admin_boundary_1st", "admin_boundary_2nd", "hospital",
    "library", "museum", "movie_theater", "zoo",
    "aquarium", "amusement_park", "golf_course", "consulate",
)

private val MEASURING_TYPES: List<String> =
    BASE_FEATURE_TYPES + listOf("border_international", "border_admin_1st", "border_admin_2nd")

private val FEATURE_TYPES: Map<String, List<String>> = mapOf(
    "matching.ask_with_name" to
        BASE_FEATURE_TYPES + listOf("admin_boundary_3rd", "admin_boundary_4th"),
    "measuring.ask_with_feature_no_name" to MEASURING_TYPES,
    "measuring.ask_with_name" to MEASURING_TYPES,
    "tentacles.ask" to BASE_FEATURE_TYPES,
)

private val SPECIAL_ARG_NAMES: Map<String, List<MessageArg>> = mapOf(
    "question.radar.ask" to listOf(PlainArg("distance")),
    "question.thermometer.start" to listOf(PlainArg("distance")),
    "question.thermometer.complete" to listOf(PlainArg("distance")),
    "question.answer.tentacles_answer" to listOf(PlainArg("answer")),
    "question.matching.ask_transit_line" to listOf(PlainArg("line")),
    "question.matching.ask_station_name_length" to listOf(PlainArg("name"), PlainArg("count")),
    "question.answer.transit_line_yes" to listOf(PlainArg("line")),
    "question.answer.transit_line_no" to listOf(PlainArg("line")),
    "round.hiding_time" to listOf(DurationArg("seconds", "time")),
    "round.hiding_time_with_bonus" to listOf(
        DurationArg("seconds", "time"),
        PlainArg("bonusMin", "0"),
        PlainArg("percent", "0"),
        PlainArg("trapMin", "0"),
        DurationArg("totalSeconds", "total"),
    ),
    "system.player_joined" to listOf(PlainArg("name")),
    "system.player_left" to listOf(PlainArg("name")),
    "system.player_removed" to listOf(PlainArg("name")),
    "system.player_rejoined" to listOf(PlainArg("name")),
    "system.question_randomized" to listOf(PlainArg("category")),
    "zone.resized" to listOf(PlainArg("radius")),
    "zone.moved_and_resized" to listOf(PlainArg("radius")),
    "zone.prosperous_home" to listOf(PlainArg("radius")),
    "zone.tiny_home" to listOf(PlainArg("radius")),
    "zone.move_played" to listOf(PlainArg("min")),
    "zone.move_played_from" to listOf(PlainArg("min"), PlainArg("station")),
    "trap.placed" to listOf(PlainArg("station"), PlainArg("increment"), PlainArg("interval")),
    "trap.detected" to listOf(PlainArg("station"), PlainArg("seeker"), PlainArg("minutes"), PlainArg("speed")),
    "trap.sprung" to listOf(PlainArg("station"), PlainArg("minutes")),
    "trap.dismissed" to listOf(PlainArg("station")),
)

private val ARGS_BY_KEY: Map<String, List<MessageArg>> = buildMap {
    FEATURE_ARG_NAMES.forEach { (pattern, arg) ->
        FEATURE_TYPES.getValue(pattern).forEach { type ->
            put("question.$pattern.$type", arg)
        }
    }
    putAll(SPECIAL_ARG_NAMES)
}

internal fun orderedArgNames(key: String): List<String>? =
    ARGS_BY_KEY[key]?.flatMap { arg ->
        if (arg is DurationArg) listOf(arg.name, arg.legacyName) else listOf(arg.name)
    }

private fun orderedArgs(context: Context, key: String, args: Map<String, String>): List<Any> =
    ARGS_BY_KEY[key].orEmpty().map { arg ->
        when (arg) {
            is PlainArg -> args[arg.name] ?: arg.default
            is DurationArg -> duration(context, args[arg.name], args[arg.legacyName])
        }
    }

/**
 * Scores travel as raw seconds so each locale words them itself. Messages posted before that carry a
 * ready-made clock string instead, so they keep rendering rather than collapsing to zero.
 */
private fun duration(context: Context, seconds: String?, legacyClock: String?): String =
    seconds?.toLongOrNull()?.let { formatDurationWords(context, it) }
        ?: legacyClock
        ?: formatDurationWords(context, 0L)
