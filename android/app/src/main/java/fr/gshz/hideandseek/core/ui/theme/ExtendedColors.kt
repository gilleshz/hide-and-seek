package fr.gshz.hideandseek.core.ui.theme

import androidx.compose.runtime.Composable
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.ReadOnlyComposable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color

/**
 * Roles Material 3 has no slot for: they must follow the light/dark scheme, and the four M3 accent
 * slots are taken (navy, hider amber, seeker vermilion, error).
 *
 * `answer` marks a hider's reply, re-emphasizing text that already says what it is, so it never has
 * to be told apart from seeker vermilion by hue alone. `warning` is a blocked-but-not-broken state
 * that would otherwise borrow the seeker's colour or error red.
 */
@Immutable
data class ExtendedColors(
    val answerContainer: Color,
    val onAnswerContainer: Color,
    val warningContainer: Color,
    val onWarningContainer: Color,
)

internal val LightExtendedColors = ExtendedColors(
    answerContainer = LightAnswerContainer,
    onAnswerContainer = LightOnAnswerContainer,
    warningContainer = LightWarningContainer,
    onWarningContainer = LightOnWarningContainer,
)

internal val DarkExtendedColors = ExtendedColors(
    answerContainer = DarkAnswerContainer,
    onAnswerContainer = DarkOnAnswerContainer,
    warningContainer = DarkWarningContainer,
    onWarningContainer = DarkOnWarningContainer,
)

internal val LocalExtendedColors = staticCompositionLocalOf { LightExtendedColors }

val extendedColors: ExtendedColors
    @Composable
    @ReadOnlyComposable
    get() = LocalExtendedColors.current
