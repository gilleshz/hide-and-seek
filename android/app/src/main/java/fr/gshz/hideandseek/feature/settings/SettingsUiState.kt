package fr.gshz.hideandseek.feature.settings

import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.ui.UiText

data class SettingsUiState(
    val selectedLocaleTag: String? = null,
    val availableLocales: List<LocaleOption> = defaultLocales,
    val selectedTheme: String = "system",
    val availableThemes: List<ThemeOption> = defaultThemes,
    val displayName: String? = null,
    val nameDraft: String = "",
    val identityUuid: String? = null,
    val isSavingName: Boolean = false,
    val nameError: ErrorType? = null,
    val nameErrorKey: String? = null,
    val avatarUrl: String? = null,
    val isUploadingAvatar: Boolean = false,
    val avatarSaved: Boolean = false,
    val avatarError: ErrorType? = null,
    val avatarErrorKey: String? = null,
    val avatarErrorArgs: Map<String, String>? = null,
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
