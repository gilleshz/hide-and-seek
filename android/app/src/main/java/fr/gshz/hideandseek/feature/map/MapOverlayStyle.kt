package fr.gshz.hideandseek.feature.map

internal object MapOverlayStyle {
    val exclusion = 0xFF1A1C2E.toInt()
    const val EXCLUSION_OPACITY = 0.45f

    val band = 0xFF001A52.toInt()
    const val BAND_OPACITY = 0.40f

    val possibleArea = 0xFF3B82F6.toInt()
    const val POSSIBLE_AREA_WIDTH = 2f

    val manualConstraint = 0xFFDC2626.toInt()
    val hidingZone = 0xFFF4C04C.toInt()
    val playerSelf = 0xFF2B3947.toInt()
    val playerOther = 0xFFE0402E.toInt()

    private const val RGB_MASK = 0xFFFFFF
    private const val HEX_RADIX = 16
    private const val HEX_WIDTH = 6

    fun hex(argb: Int): String = "#" + (argb and RGB_MASK).toString(HEX_RADIX).padStart(HEX_WIDTH, '0')
}
