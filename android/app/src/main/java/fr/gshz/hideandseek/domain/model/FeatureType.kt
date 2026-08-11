package fr.gshz.hideandseek.domain.model

import androidx.annotation.StringRes
import fr.gshz.hideandseek.R

/**
 * POI categories a Matching/Measuring/Tentacles question can target. [minGameSize] and the
 * per-category availability follow the game rules; the UI filters by both.
 */
enum class FeatureType(val wireValue: String, @get:StringRes val labelRes: Int) {
    TransitStation("transit_station", R.string.feature_type_transit_station),
    CommercialAirport("commercial_airport", R.string.feature_type_commercial_airport),
    RailStation("rail_station", R.string.feature_type_rail_station),
    MetroLine("metro_line", R.string.feature_type_metro_line),
    HighSpeedRailLine("high_speed_rail_line", R.string.feature_type_high_speed_rail_line),
    Mountain("mountain", R.string.feature_type_mountain),
    Park("park", R.string.feature_type_park),
    BodyOfWater("body_of_water", R.string.feature_type_body_of_water),
    Coastline("coastline", R.string.feature_type_coastline),
    BorderInternational("border_international", R.string.feature_type_border_international),
    Border1st("border_admin_1st", R.string.feature_type_border_admin_1st),
    Border2nd("border_admin_2nd", R.string.feature_type_border_admin_2nd),
    AdminBoundary1st("admin_boundary_1st", R.string.feature_type_admin_boundary_1st),
    AdminBoundary2nd("admin_boundary_2nd", R.string.feature_type_admin_boundary_2nd),
    AdminBoundary3rd("admin_boundary_3rd", R.string.feature_type_admin_boundary_3rd),
    AdminBoundary4th("admin_boundary_4th", R.string.feature_type_admin_boundary_4th),
    Hospital("hospital", R.string.feature_type_hospital),
    Library("library", R.string.feature_type_library),
    Museum("museum", R.string.feature_type_museum),
    MovieTheater("movie_theater", R.string.feature_type_movie_theater),
    Zoo("zoo", R.string.feature_type_zoo),
    Aquarium("aquarium", R.string.feature_type_aquarium),
    AmusementPark("amusement_park", R.string.feature_type_amusement_park),
    GolfCourse("golf_course", R.string.feature_type_golf_course),
    Consulate("consulate", R.string.feature_type_consulate),
    ;

    companion object {
        fun fromWireValue(value: String): FeatureType? = entries.firstOrNull { it.wireValue == value }
    }
}
