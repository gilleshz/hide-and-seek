package fr.gshz.hideandseek.feature.settings

import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.ui.UiText

data class SettingsUiState(
    val selectedLocaleTag: String? = null,
    val availableLocales: List<LocaleOption> = defaultLocales,
    val selectedTheme: String = "system",
    val availableThemes: List<ThemeOption> = defaultThemes,
    val accountName: String? = null,
    val currentPasswordInput: String = "",
    val newPasswordInput: String = "",
    val isChangingPassword: Boolean = false,
    val passwordError: ErrorType? = null,
    val passwordErrorKey: String? = null,
    val passwordChanged: Boolean = false,
)

data class LocaleOption(
    val tag: String,
    val displayName: UiText,
)

data class ThemeOption(
    val tag: String,
    val displayName: UiText,
)

val defaultLocales = listOf(
    LocaleOption("", UiText.fromResource(R.string.settings_language_system_default)),
    LocaleOption("en", UiText.Dynamic("English")),
    LocaleOption("fr", UiText.Dynamic("Français")),
    LocaleOption("de", UiText.Dynamic("Deutsch")),
)

val defaultThemes = listOf(
    ThemeOption("system", UiText.fromResource(R.string.settings_theme_system_default)),
    ThemeOption("light", UiText.fromResource(R.string.settings_theme_light)),
    ThemeOption("dark", UiText.fromResource(R.string.settings_theme_dark)),
)
