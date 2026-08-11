package fr.gshz.hideandseek.core.ui.theme

import androidx.compose.material3.Typography
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontVariation
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import fr.gshz.hideandseek.R

@OptIn(androidx.compose.ui.text.ExperimentalTextApi::class)
val Baloo2 = FontFamily(
    Font(
        R.font.baloo2,
        weight = FontWeight.Medium,
        variationSettings = FontVariation.Settings(FontVariation.weight(FontWeight.Medium.weight)),
    ),
    Font(
        R.font.baloo2,
        weight = FontWeight.SemiBold,
        variationSettings = FontVariation.Settings(FontVariation.weight(FontWeight.SemiBold.weight)),
    ),
    Font(
        R.font.baloo2,
        weight = FontWeight.Bold,
        variationSettings = FontVariation.Settings(FontVariation.weight(FontWeight.Bold.weight)),
    ),
)

private val default = Typography()

val Typography = Typography(
    displayLarge = default.displayLarge.copy(
        fontFamily = Baloo2,
        fontWeight = FontWeight.Bold,
        fontSize = 57.sp,
        lineHeight = 64.sp,
        letterSpacing = (-0.5).sp,
    ),
    displayMedium = default.displayMedium.copy(
        fontFamily = Baloo2,
        fontWeight = FontWeight.Bold,
        fontSize = 45.sp,
        lineHeight = 52.sp,
    ),
    displaySmall = default.displaySmall.copy(
        fontFamily = Baloo2,
        fontWeight = FontWeight.SemiBold,
        fontSize = 36.sp,
        lineHeight = 44.sp,
    ),
    headlineLarge = default.headlineLarge.copy(
        fontFamily = Baloo2,
        fontWeight = FontWeight.SemiBold,
        fontSize = 32.sp,
        lineHeight = 40.sp,
    ),
    headlineMedium = default.headlineMedium.copy(
        fontFamily = Baloo2,
        fontWeight = FontWeight.SemiBold,
        fontSize = 28.sp,
        lineHeight = 36.sp,
    ),
    headlineSmall = default.headlineSmall.copy(
        fontFamily = Baloo2,
        fontWeight = FontWeight.SemiBold,
        fontSize = 24.sp,
        lineHeight = 32.sp,
    ),
    titleLarge = default.titleLarge.copy(
        fontFamily = Baloo2,
        fontWeight = FontWeight.SemiBold,
        fontSize = 22.sp,
        lineHeight = 28.sp,
    ),
    titleMedium = default.titleMedium.copy(
        fontWeight = FontWeight.SemiBold,
        fontSize = 16.sp,
        lineHeight = 24.sp,
        letterSpacing = 0.15.sp,
    ),
    titleSmall = default.titleSmall.copy(
        fontWeight = FontWeight.Medium,
        fontSize = 14.sp,
        lineHeight = 20.sp,
        letterSpacing = 0.1.sp,
    ),
    bodyLarge = default.bodyLarge.copy(fontSize = 16.sp, lineHeight = 24.sp),
    bodyMedium = default.bodyMedium.copy(fontSize = 14.sp, lineHeight = 20.sp),
    bodySmall = default.bodySmall.copy(fontSize = 12.sp, lineHeight = 16.sp),
    labelLarge = default.labelLarge.copy(fontWeight = FontWeight.SemiBold, fontSize = 14.sp, lineHeight = 20.sp),
    labelMedium = default.labelMedium.copy(fontWeight = FontWeight.Medium, fontSize = 12.sp, lineHeight = 16.sp),
    labelSmall = default.labelSmall.copy(fontWeight = FontWeight.Medium, fontSize = 11.sp, lineHeight = 16.sp),
)
