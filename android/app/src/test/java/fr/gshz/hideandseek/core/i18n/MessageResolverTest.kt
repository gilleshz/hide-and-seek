package fr.gshz.hideandseek.core.i18n

import fr.gshz.hideandseek.R
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

class MessageResolverTest {

    private data class Case(
        val key: String,
        val resId: Int,
        val argNames: List<String>,
    )

    private val cases: List<Case> = listOf(
        Case("question.radar.ask", R.string.question_radar_ask, listOf("distance")),
        Case(
            "question.matching.ask_no_name.transit_station",
            R.string.question_matching_ask_no_name_transit_station,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.transit_station",
            R.string.question_matching_ask_with_name_transit_station,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.transit_station",
            R.string.question_measuring_ask_no_name_transit_station,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.transit_station",
            R.string.question_measuring_ask_with_feature_no_name_transit_station,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.transit_station",
            R.string.question_measuring_ask_with_name_transit_station,
            listOf("distance", "name"),
        ),
        Case(
            "question.tentacles.ask.transit_station",
            R.string.question_tentacles_ask_transit_station,
            listOf("range"),
        ),
        Case(
            "question.matching.ask_no_name.commercial_airport",
            R.string.question_matching_ask_no_name_commercial_airport,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.commercial_airport",
            R.string.question_matching_ask_with_name_commercial_airport,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.commercial_airport",
            R.string.question_measuring_ask_no_name_commercial_airport,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.commercial_airport",
            R.string.question_measuring_ask_with_feature_no_name_commercial_airport,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.commercial_airport",
            R.string.question_measuring_ask_with_name_commercial_airport,
            listOf("distance", "name"),
        ),
        Case(
            "question.tentacles.ask.commercial_airport",
            R.string.question_tentacles_ask_commercial_airport,
            listOf("range"),
        ),
        Case(
            "question.matching.ask_no_name.rail_station",
            R.string.question_matching_ask_no_name_rail_station,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.rail_station",
            R.string.question_matching_ask_with_name_rail_station,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.rail_station",
            R.string.question_measuring_ask_no_name_rail_station,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.rail_station",
            R.string.question_measuring_ask_with_feature_no_name_rail_station,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.rail_station",
            R.string.question_measuring_ask_with_name_rail_station,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.rail_station", R.string.question_tentacles_ask_rail_station, listOf("range")),
        Case(
            "question.matching.ask_no_name.metro_line",
            R.string.question_matching_ask_no_name_metro_line,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.metro_line",
            R.string.question_matching_ask_with_name_metro_line,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.metro_line",
            R.string.question_measuring_ask_no_name_metro_line,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.metro_line",
            R.string.question_measuring_ask_with_feature_no_name_metro_line,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.metro_line",
            R.string.question_measuring_ask_with_name_metro_line,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.metro_line", R.string.question_tentacles_ask_metro_line, listOf("range")),
        Case(
            "question.matching.ask_no_name.high_speed_rail_line",
            R.string.question_matching_ask_no_name_high_speed_rail_line,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.high_speed_rail_line",
            R.string.question_matching_ask_with_name_high_speed_rail_line,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.high_speed_rail_line",
            R.string.question_measuring_ask_no_name_high_speed_rail_line,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.high_speed_rail_line",
            R.string.question_measuring_ask_with_feature_no_name_high_speed_rail_line,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.high_speed_rail_line",
            R.string.question_measuring_ask_with_name_high_speed_rail_line,
            listOf("distance", "name"),
        ),
        Case(
            "question.tentacles.ask.high_speed_rail_line",
            R.string.question_tentacles_ask_high_speed_rail_line,
            listOf("range"),
        ),
        Case("question.matching.ask_no_name.mountain", R.string.question_matching_ask_no_name_mountain, emptyList()),
        Case(
            "question.matching.ask_with_name.mountain",
            R.string.question_matching_ask_with_name_mountain,
            listOf("name"),
        ),
        Case("question.measuring.ask_no_name.mountain", R.string.question_measuring_ask_no_name_mountain, emptyList()),
        Case(
            "question.measuring.ask_with_feature_no_name.mountain",
            R.string.question_measuring_ask_with_feature_no_name_mountain,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.mountain",
            R.string.question_measuring_ask_with_name_mountain,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.mountain", R.string.question_tentacles_ask_mountain, listOf("range")),
        Case("question.matching.ask_no_name.park", R.string.question_matching_ask_no_name_park, emptyList()),
        Case("question.matching.ask_with_name.park", R.string.question_matching_ask_with_name_park, listOf("name")),
        Case("question.measuring.ask_no_name.park", R.string.question_measuring_ask_no_name_park, emptyList()),
        Case(
            "question.measuring.ask_with_feature_no_name.park",
            R.string.question_measuring_ask_with_feature_no_name_park,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.park",
            R.string.question_measuring_ask_with_name_park,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.park", R.string.question_tentacles_ask_park, listOf("range")),
        Case(
            "question.matching.ask_no_name.body_of_water",
            R.string.question_matching_ask_no_name_body_of_water,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.body_of_water",
            R.string.question_matching_ask_with_name_body_of_water,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.body_of_water",
            R.string.question_measuring_ask_no_name_body_of_water,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.body_of_water",
            R.string.question_measuring_ask_with_feature_no_name_body_of_water,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.body_of_water",
            R.string.question_measuring_ask_with_name_body_of_water,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.body_of_water", R.string.question_tentacles_ask_body_of_water, listOf("range")),
        Case("question.matching.ask_no_name.coastline", R.string.question_matching_ask_no_name_coastline, emptyList()),
        Case(
            "question.matching.ask_with_name.coastline",
            R.string.question_matching_ask_with_name_coastline,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.coastline",
            R.string.question_measuring_ask_no_name_coastline,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.coastline",
            R.string.question_measuring_ask_with_feature_no_name_coastline,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.coastline",
            R.string.question_measuring_ask_with_name_coastline,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.coastline", R.string.question_tentacles_ask_coastline, listOf("range")),
        Case(
            "question.measuring.ask_no_name.border_international",
            R.string.question_measuring_ask_no_name_border_international,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.border_international",
            R.string.question_measuring_ask_with_feature_no_name_border_international,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.border_international",
            R.string.question_measuring_ask_with_name_border_international,
            listOf("distance", "name"),
        ),
        Case(
            "question.measuring.ask_no_name.border_admin_1st",
            R.string.question_measuring_ask_no_name_border_admin_1st,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.border_admin_1st",
            R.string.question_measuring_ask_with_feature_no_name_border_admin_1st,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.border_admin_1st",
            R.string.question_measuring_ask_with_name_border_admin_1st,
            listOf("distance", "name"),
        ),
        Case(
            "question.measuring.ask_no_name.border_admin_2nd",
            R.string.question_measuring_ask_no_name_border_admin_2nd,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.border_admin_2nd",
            R.string.question_measuring_ask_with_feature_no_name_border_admin_2nd,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.border_admin_2nd",
            R.string.question_measuring_ask_with_name_border_admin_2nd,
            listOf("distance", "name"),
        ),
        Case(
            "question.matching.ask_no_name.admin_boundary_1st",
            R.string.question_matching_ask_no_name_admin_boundary_1st,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.admin_boundary_1st",
            R.string.question_matching_ask_with_name_admin_boundary_1st,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.admin_boundary_1st",
            R.string.question_measuring_ask_no_name_admin_boundary_1st,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.admin_boundary_1st",
            R.string.question_measuring_ask_with_feature_no_name_admin_boundary_1st,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.admin_boundary_1st",
            R.string.question_measuring_ask_with_name_admin_boundary_1st,
            listOf("distance", "name"),
        ),
        Case(
            "question.tentacles.ask.admin_boundary_1st",
            R.string.question_tentacles_ask_admin_boundary_1st,
            listOf("range"),
        ),
        Case(
            "question.matching.ask_no_name.admin_boundary_2nd",
            R.string.question_matching_ask_no_name_admin_boundary_2nd,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.admin_boundary_2nd",
            R.string.question_matching_ask_with_name_admin_boundary_2nd,
            listOf("name"),
        ),
        Case(
            "question.matching.ask_no_name.admin_boundary_3rd",
            R.string.question_matching_ask_no_name_admin_boundary_3rd,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.admin_boundary_3rd",
            R.string.question_matching_ask_with_name_admin_boundary_3rd,
            listOf("name"),
        ),
        Case(
            "question.matching.ask_no_name.admin_boundary_4th",
            R.string.question_matching_ask_no_name_admin_boundary_4th,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.admin_boundary_4th",
            R.string.question_matching_ask_with_name_admin_boundary_4th,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.admin_boundary_2nd",
            R.string.question_measuring_ask_no_name_admin_boundary_2nd,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.admin_boundary_2nd",
            R.string.question_measuring_ask_with_feature_no_name_admin_boundary_2nd,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.admin_boundary_2nd",
            R.string.question_measuring_ask_with_name_admin_boundary_2nd,
            listOf("distance", "name"),
        ),
        Case(
            "question.tentacles.ask.admin_boundary_2nd",
            R.string.question_tentacles_ask_admin_boundary_2nd,
            listOf("range"),
        ),
        Case("question.matching.ask_no_name.hospital", R.string.question_matching_ask_no_name_hospital, emptyList()),
        Case(
            "question.matching.ask_with_name.hospital",
            R.string.question_matching_ask_with_name_hospital,
            listOf("name"),
        ),
        Case("question.measuring.ask_no_name.hospital", R.string.question_measuring_ask_no_name_hospital, emptyList()),
        Case(
            "question.measuring.ask_with_feature_no_name.hospital",
            R.string.question_measuring_ask_with_feature_no_name_hospital,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.hospital",
            R.string.question_measuring_ask_with_name_hospital,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.hospital", R.string.question_tentacles_ask_hospital, listOf("range")),
        Case("question.matching.ask_no_name.library", R.string.question_matching_ask_no_name_library, emptyList()),
        Case(
            "question.matching.ask_with_name.library",
            R.string.question_matching_ask_with_name_library,
            listOf("name"),
        ),
        Case("question.measuring.ask_no_name.library", R.string.question_measuring_ask_no_name_library, emptyList()),
        Case(
            "question.measuring.ask_with_feature_no_name.library",
            R.string.question_measuring_ask_with_feature_no_name_library,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.library",
            R.string.question_measuring_ask_with_name_library,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.library", R.string.question_tentacles_ask_library, listOf("range")),
        Case("question.matching.ask_no_name.museum", R.string.question_matching_ask_no_name_museum, emptyList()),
        Case("question.matching.ask_with_name.museum", R.string.question_matching_ask_with_name_museum, listOf("name")),
        Case("question.measuring.ask_no_name.museum", R.string.question_measuring_ask_no_name_museum, emptyList()),
        Case(
            "question.measuring.ask_with_feature_no_name.museum",
            R.string.question_measuring_ask_with_feature_no_name_museum,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.museum",
            R.string.question_measuring_ask_with_name_museum,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.museum", R.string.question_tentacles_ask_museum, listOf("range")),
        Case(
            "question.matching.ask_no_name.movie_theater",
            R.string.question_matching_ask_no_name_movie_theater,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.movie_theater",
            R.string.question_matching_ask_with_name_movie_theater,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.movie_theater",
            R.string.question_measuring_ask_no_name_movie_theater,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.movie_theater",
            R.string.question_measuring_ask_with_feature_no_name_movie_theater,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.movie_theater",
            R.string.question_measuring_ask_with_name_movie_theater,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.movie_theater", R.string.question_tentacles_ask_movie_theater, listOf("range")),
        Case("question.matching.ask_no_name.zoo", R.string.question_matching_ask_no_name_zoo, emptyList()),
        Case("question.matching.ask_with_name.zoo", R.string.question_matching_ask_with_name_zoo, listOf("name")),
        Case("question.measuring.ask_no_name.zoo", R.string.question_measuring_ask_no_name_zoo, emptyList()),
        Case(
            "question.measuring.ask_with_feature_no_name.zoo",
            R.string.question_measuring_ask_with_feature_no_name_zoo,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.zoo",
            R.string.question_measuring_ask_with_name_zoo,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.zoo", R.string.question_tentacles_ask_zoo, listOf("range")),
        Case("question.matching.ask_no_name.aquarium", R.string.question_matching_ask_no_name_aquarium, emptyList()),
        Case(
            "question.matching.ask_with_name.aquarium",
            R.string.question_matching_ask_with_name_aquarium,
            listOf("name"),
        ),
        Case("question.measuring.ask_no_name.aquarium", R.string.question_measuring_ask_no_name_aquarium, emptyList()),
        Case(
            "question.measuring.ask_with_feature_no_name.aquarium",
            R.string.question_measuring_ask_with_feature_no_name_aquarium,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.aquarium",
            R.string.question_measuring_ask_with_name_aquarium,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.aquarium", R.string.question_tentacles_ask_aquarium, listOf("range")),
        Case(
            "question.matching.ask_no_name.amusement_park",
            R.string.question_matching_ask_no_name_amusement_park,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.amusement_park",
            R.string.question_matching_ask_with_name_amusement_park,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.amusement_park",
            R.string.question_measuring_ask_no_name_amusement_park,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.amusement_park",
            R.string.question_measuring_ask_with_feature_no_name_amusement_park,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.amusement_park",
            R.string.question_measuring_ask_with_name_amusement_park,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.amusement_park", R.string.question_tentacles_ask_amusement_park, listOf("range")),
        Case(
            "question.matching.ask_no_name.golf_course",
            R.string.question_matching_ask_no_name_golf_course,
            emptyList(),
        ),
        Case(
            "question.matching.ask_with_name.golf_course",
            R.string.question_matching_ask_with_name_golf_course,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.golf_course",
            R.string.question_measuring_ask_no_name_golf_course,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.golf_course",
            R.string.question_measuring_ask_with_feature_no_name_golf_course,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.golf_course",
            R.string.question_measuring_ask_with_name_golf_course,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.golf_course", R.string.question_tentacles_ask_golf_course, listOf("range")),
        Case("question.matching.ask_no_name.consulate", R.string.question_matching_ask_no_name_consulate, emptyList()),
        Case(
            "question.matching.ask_with_name.consulate",
            R.string.question_matching_ask_with_name_consulate,
            listOf("name"),
        ),
        Case(
            "question.measuring.ask_no_name.consulate",
            R.string.question_measuring_ask_no_name_consulate,
            emptyList(),
        ),
        Case(
            "question.measuring.ask_with_feature_no_name.consulate",
            R.string.question_measuring_ask_with_feature_no_name_consulate,
            listOf("distance"),
        ),
        Case(
            "question.measuring.ask_with_name.consulate",
            R.string.question_measuring_ask_with_name_consulate,
            listOf("distance", "name"),
        ),
        Case("question.tentacles.ask.consulate", R.string.question_tentacles_ask_consulate, listOf("range")),
        Case("question.photos.ask.tree", R.string.question_photos_ask_tree, emptyList()),
        Case("question.photos.ask.sky", R.string.question_photos_ask_sky, emptyList()),
        Case("question.photos.ask.selfie", R.string.question_photos_ask_selfie, emptyList()),
        Case("question.photos.ask.widest_street", R.string.question_photos_ask_widest_street, emptyList()),
        Case(
            "question.photos.ask.tallest_structure_in_sightline",
            R.string.question_photos_ask_tallest_structure_in_sightline,
            emptyList(),
        ),
        Case(
            "question.photos.ask.building_visible_from_station",
            R.string.question_photos_ask_building_visible_from_station,
            emptyList(),
        ),
        Case(
            "question.photos.ask.tallest_building_from_station",
            R.string.question_photos_ask_tallest_building_from_station,
            emptyList(),
        ),
        Case(
            "question.photos.ask.trace_nearest_street",
            R.string.question_photos_ask_trace_nearest_street,
            emptyList(),
        ),
        Case("question.photos.ask.two_buildings", R.string.question_photos_ask_two_buildings, emptyList()),
        Case("question.photos.ask.restaurant_interior", R.string.question_photos_ask_restaurant_interior, emptyList()),
        Case("question.photos.ask.train_platform", R.string.question_photos_ask_train_platform, emptyList()),
        Case("question.photos.ask.park", R.string.question_photos_ask_park, emptyList()),
        Case("question.photos.ask.grocery_store_aisle", R.string.question_photos_ask_grocery_store_aisle, emptyList()),
        Case("question.photos.ask.place_of_worship", R.string.question_photos_ask_place_of_worship, emptyList()),
        Case(
            "question.photos.ask.streets_traced_metric",
            R.string.question_photos_ask_streets_traced_metric,
            emptyList(),
        ),
        Case(
            "question.photos.ask.streets_traced_imperial",
            R.string.question_photos_ask_streets_traced_imperial,
            emptyList(),
        ),
        Case(
            "question.photos.ask.tallest_mountain_from_station",
            R.string.question_photos_ask_tallest_mountain_from_station,
            emptyList(),
        ),
        Case(
            "question.photos.ask.biggest_body_of_water_in_zone",
            R.string.question_photos_ask_biggest_body_of_water_in_zone,
            emptyList(),
        ),
        Case("question.photos.ask.five_buildings", R.string.question_photos_ask_five_buildings, emptyList()),
        Case("question.thermometer.start", R.string.question_thermometer_start, listOf("distance")),
        Case("question.thermometer.complete", R.string.question_thermometer_complete, listOf("distance")),
        Case("question.answer.yes_within_range", R.string.question_answer_yes_within_range, emptyList()),
        Case("question.answer.no_not_within_range", R.string.question_answer_no_not_within_range, emptyList()),
        Case("question.answer.same", R.string.question_answer_same, emptyList()),
        Case("question.answer.different", R.string.question_answer_different, emptyList()),
        Case("question.answer.hotter", R.string.question_answer_hotter, emptyList()),
        Case("question.answer.colder", R.string.question_answer_colder, emptyList()),
        Case("question.answer.closer", R.string.question_answer_closer, emptyList()),
        Case("question.answer.further", R.string.question_answer_further, emptyList()),
        Case("question.answer.sea_level_closer", R.string.question_answer_sea_level_closer, emptyList()),
        Case("question.answer.sea_level_further", R.string.question_answer_sea_level_further, emptyList()),
        Case("question.measuring.ask.sea_level", R.string.question_measuring_ask_sea_level, emptyList()),
        Case("question.answer.no_answer", R.string.question_answer_no_answer, emptyList()),
        Case("question.answer.photo_in_chat", R.string.question_answer_photo_in_chat, emptyList()),
        Case("question.answer.tentacles_answer", R.string.question_answer_tentacles_answer, listOf("answer")),
        Case("question.matching.ask_transit_line", R.string.question_matching_ask_transit_line, listOf("line")),
        Case(
            "question.matching.ask_station_name_length",
            R.string.question_matching_ask_station_name_length,
            listOf("name", "count"),
        ),
        Case("question.answer.transit_line_yes", R.string.question_answer_transit_line_yes, listOf("line")),
        Case("question.answer.transit_line_no", R.string.question_answer_transit_line_no, listOf("line")),
        Case("system.player_joined", R.string.system_player_joined, listOf("name")),
        Case("system.player_left", R.string.system_player_left, listOf("name")),
        Case("system.player_removed", R.string.system_player_removed, listOf("name")),
        Case("system.player_rejoined", R.string.system_player_rejoined, listOf("name")),
        Case("system.question_cancelled", R.string.system_question_cancelled, emptyList()),
        Case("system.question_vetoed", R.string.system_question_vetoed, emptyList()),
        Case("system.question_randomized", R.string.system_question_randomized, listOf("category")),
        Case("round.hiding_time", R.string.round_hiding_time, listOf("seconds", "time")),
        Case(
            "round.hiding_time_with_bonus",
            R.string.round_hiding_time_with_bonus,
            listOf("seconds", "time", "bonusMin", "percent", "trapMin", "totalSeconds", "total"),
        ),
        Case("zone.moved", R.string.zone_moved, emptyList()),
        Case("zone.resized", R.string.zone_resized, listOf("radius")),
        Case("zone.moved_and_resized", R.string.zone_moved_and_resized, listOf("radius")),
        Case("zone.prosperous_home", R.string.zone_prosperous_home, listOf("radius")),
        Case("zone.tiny_home", R.string.zone_tiny_home, listOf("radius")),
        Case("zone.move_played", R.string.zone_move_played, listOf("min")),
        Case("zone.move_played_from", R.string.zone_move_played_from, listOf("min", "station")),
        Case("round.move_period_over", R.string.round_move_period_over, emptyList()),
        Case("trap.placed", R.string.trap_placed, listOf("station", "increment", "interval")),
        Case("trap.detected", R.string.trap_detected, listOf("station", "seeker", "minutes", "speed")),
        Case("trap.sprung", R.string.trap_sprung, listOf("station", "minutes")),
        Case("trap.dismissed", R.string.trap_dismissed, listOf("station")),
    )

    @Test
    fun `every key resolves to its convention resource`() {
        cases.forEach { case ->
            assertEquals(case.resId, messageKeyToResId(case.key), case.key)
        }
    }

    @Test
    fun `every key maps to the resource named with dots as underscores`() {
        cases.forEach { case ->
            val field = R.string::class.java.getField(case.key.replace('.', '_'))
            assertEquals(case.resId, field.getInt(null), case.key)
        }
    }

    @Test
    fun `every key fills exactly its documented format args in order`() {
        cases.forEach { case ->
            assertEquals(case.argNames, orderedArgNames(case.key).orEmpty(), case.key)
        }
    }

    @Test
    fun `keys outside the table fall back instead of resolving`() {
        assertNull(messageKeyToResId("game.not_found"))
        assertNull(messageKeyToResId("error.seeker_only"))
        assertNull(messageKeyToResId("unknown.key"))
        assertNull(messageKeyToResId(""))
    }

    @Test
    fun `keys without format args have no arg table entry`() {
        cases.filter { it.argNames.isEmpty() }.forEach { case ->
            assertNull(orderedArgNames(case.key))
        }
    }

    @Test
    fun `the format args reach the formatter as one element per placeholder`() {
        val captured = mutableListOf<Array<out Any>>()
        val rendered = formatMessage(
            { _, args -> captured.add(args); "rendered" },
            R.string.zone_moved,
            listOf("123"),
        )

        assertEquals("rendered", rendered)
        assertEquals(1, captured.single().size)
        assertEquals("123", captured.single()[0])
    }

    @Test
    fun `a multi-arg key orders the placeholders as documented`() {
        val captured = mutableListOf<Array<out Any>>()
        formatMessage(
            { _, args -> captured.add(args); "" },
            R.string.trap_detected,
            orderedArgs(
                "trap.detected",
                mapOf("station" to "Flon", "seeker" to "Bob", "minutes" to "5", "speed" to "12"),
                { seconds, _ -> seconds ?: "" },
            ),
        )

        assertEquals(listOf("Flon", "Bob", "5", "12"), captured.single().toList())
    }

    @Test
    fun `a missing plain arg falls back to its documented default`() {
        assertEquals(listOf(""), orderedArgs("zone.resized", emptyMap()) { seconds, _ -> seconds ?: "" })
    }

    @Test
    fun `a duration arg renders through the injected formatter`() {
        val args = orderedArgs("round.hiding_time", mapOf("seconds" to "3600")) { seconds, _ ->
            "DURATION($seconds)"
        }

        assertEquals(listOf("DURATION(3600)"), args)
    }
}
