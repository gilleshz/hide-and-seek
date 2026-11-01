package fr.gshz.hideandseek.feature.map

internal object MapOverlayStyle {
    val exclusion = 0xFF1A1C2E.toInt()
    const val EXCLUSION_OPACITY = 0.45f

    val band = 0xFF1A1C2E.toInt()
    const val BAND_BACKDROP_OPACITY = 0.25f
    const val BAND_STRIPE = 0x521A1C2E
    const val BAND_HATCH_TILE_DP = 8f
    const val BAND_HATCH_STRIPE_DP = 0.6f
    const val BAND_BORDER_OPACITY = 0.32f

    val possibleArea = 0xFF3B82F6.toInt()
    const val POSSIBLE_AREA_WIDTH = 2f
    const val BAND_BORDER_WIDTH = POSSIBLE_AREA_WIDTH

    val manualConstraint = 0xFFDC2626.toInt()
    val hidingZone = 0xFFF4C04C.toInt()
    val playerSelf = 0xFF2B3947.toInt()
    val playerOther = 0xFFE0402E.toInt()

    private const val RGB_MASK = 0xFFFFFF
    private const val HEX_RADIX = 16
    private const val HEX_WIDTH = 6

    fun hex(argb: Int): String = "#" + (argb and RGB_MASK).toString(HEX_RADIX).padStart(HEX_WIDTH, '0')
}
