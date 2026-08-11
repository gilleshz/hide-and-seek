package fr.gshz.hideandseek.domain.model

import androidx.annotation.StringRes
import fr.gshz.hideandseek.R

/**
 * Photo-question subjects per the game rules, gated by game size.
 * Mirrors the backend PhotoTarget catalog (wire values and size availability).
 */
enum class PhotoTarget(
    val wireValue: String,
    @get:StringRes private val defaultLabelRes: Int,
    val minimumSize: GameSize,
) {
    Tree("tree", R.string.photo_target_tree, GameSize.Small),
    Sky("sky", R.string.photo_target_sky, GameSize.Small),
    Selfie("selfie", R.string.photo_target_selfie, GameSize.Small),
    WidestStreet("widest_street", R.string.photo_target_widest_street, GameSize.Small),
    TallestStructureInSightline(
        "tallest_structure_in_sightline",
        R.string.photo_target_tallest_structure_in_sightline,
        GameSize.Small,
    ),
    BuildingVisibleFromStation(
        "building_visible_from_station",
        R.string.photo_target_building_visible_from_station,
        GameSize.Small,
    ),
    TallestBuildingVisibleFromStation(
        "tallest_building_from_station",
        R.string.photo_target_tallest_building_from_station,
        GameSize.Medium,
    ),
    TraceNearestStreet("trace_nearest_street", R.string.photo_target_trace_nearest_street, GameSize.Medium),
    TwoBuildings("two_buildings", R.string.photo_target_two_buildings, GameSize.Medium),
    RestaurantInterior("restaurant_interior", R.string.photo_target_restaurant_interior, GameSize.Medium),
    TrainPlatform("train_platform", R.string.photo_target_train_platform, GameSize.Medium),
    Park("park", R.string.photo_target_park, GameSize.Medium),
    GroceryStoreAisle("grocery_store_aisle", R.string.photo_target_grocery_store_aisle, GameSize.Medium),
    PlaceOfWorship("place_of_worship", R.string.photo_target_place_of_worship, GameSize.Medium),
    StreetsTraced("streets_traced", R.string.photo_target_streets_traced_metric, GameSize.Large),
    TallestMountainVisibleFromStation(
        "tallest_mountain_from_station",
        R.string.photo_target_tallest_mountain_from_station,
        GameSize.Large,
    ),
    BiggestBodyOfWaterInZone(
        "biggest_body_of_water_in_zone",
        R.string.photo_target_biggest_body_of_water_in_zone,
        GameSize.Large,
    ),
    FiveBuildings("five_buildings", R.string.photo_target_five_buildings, GameSize.Large),
    ;

    val isTraceable: Boolean get() = this == TraceNearestStreet || this == StreetsTraced

    fun isAvailableFor(size: GameSize): Boolean = size.ordinal >= minimumSize.ordinal

    @StringRes
    fun labelRes(edition: Edition): Int = when (this) {
        StreetsTraced -> when (edition) {
            Edition.Metric -> R.string.photo_target_streets_traced_metric
            Edition.Imperial -> R.string.photo_target_streets_traced_imperial
        }
        else -> defaultLabelRes
    }

    companion object {
        fun fromWireValue(value: String): PhotoTarget? = entries.firstOrNull { it.wireValue == value }
    }
}
