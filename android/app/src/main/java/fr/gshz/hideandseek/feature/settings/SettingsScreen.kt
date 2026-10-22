package fr.gshz.hideandseek.feature.settings

import android.app.Activity
import android.graphics.Bitmap
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import fr.gshz.hideandseek.BuildConfig
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.ui.ProfileAvatar
import fr.gshz.hideandseek.core.ui.rememberPhotoSourcePicker
import fr.gshz.hideandseek.core.ui.resolve
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.feature.settings.avatar.AvatarCropScreen

private val PROFILE_AVATAR_SIZE = 56.dp
private const val UPLOAD_OVERLAY_ALPHA = 0.45f

@Suppress("LongMethod")
@Composable
fun SettingsScreen(
    onBackClick: () -> Unit,
    viewModel: SettingsViewModel = hiltViewModel(),
    modifier: Modifier = Modifier,
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val context = LocalContext.current
    var cropSource by rememberSaveable { mutableStateOf<String?>(null) }
    var showRemoveDialog by rememberSaveable { mutableStateOf(false) }
    val pickPhoto = rememberPhotoSourcePicker(
        onPicked = { cropSource = it },
        titleRes = R.string.settings_profile_photo,
        messageRes = R.string.settings_profile_photo_hint,
    )
    LaunchedEffect(uiState.avatarSaved) {
        if (uiState.avatarSaved) {
            cropSource = null
            viewModel.consumeAvatarSaved()
        }
    }

    val crop = cropSource
    if (crop != null) {
        AvatarCropHost(
            source = Uri.parse(crop),
            uiState = uiState,
            onCancel = {
                cropSource = null
                viewModel.clearAvatarError()
            },
            onUsePhoto = viewModel::uploadAvatar,
        )
        return
    }

    SettingsContent(
        uiState = uiState,
        versionName = BuildConfig.VERSION_NAME,
        onLocaleSelected = { tag ->
            viewModel.onLocaleSelected(tag)
            (context as? Activity)?.recreate()
        },
        onThemeSelected = viewModel::onThemeSelected,
        onPickAvatar = pickPhoto,
        onRemoveAvatar = { showRemoveDialog = true },
        onBackClick = onBackClick,
        modifier = modifier,
    )

    if (showRemoveDialog) {
        RemoveAvatarDialog(
            onDismiss = { showRemoveDialog = false },
            onConfirm = {
                showRemoveDialog = false
                viewModel.removeAvatar()
            },
        )
    }
}

@Composable
private fun AvatarCropHost(
    source: Uri,
    uiState: SettingsUiState,
    onCancel: () -> Unit,
    onUsePhoto: (Bitmap) -> Unit,
) {
    AvatarCropScreen(
        source = source,
        isUploading = uiState.isUploadingAvatar,
        error = uiState.avatarError,
        errorKey = uiState.avatarErrorKey,
        errorArgs = uiState.avatarErrorArgs,
        onCancel = onCancel,
        onUsePhoto = onUsePhoto,
    )
}

@Composable
private fun RemoveAvatarDialog(onDismiss: () -> Unit, onConfirm: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.settings_profile_photo_remove_title)) },
        text = { Text(stringResource(R.string.settings_profile_photo_remove_body)) },
        confirmButton = {
            TextButton(
                onClick = onConfirm,
                colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error),
            ) {
                Text(stringResource(R.string.settings_profile_photo_remove_confirm))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text(stringResource(android.R.string.cancel))
            }
        },
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Suppress("LongParameterList", "LongMethod")
@Composable
internal fun SettingsContent(
    uiState: SettingsUiState,
    versionName: String,
    onLocaleSelected: (String) -> Unit,
    onThemeSelected: (String) -> Unit,
    onPickAvatar: () -> Unit,
    onRemoveAvatar: () -> Unit,
    onBackClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.settings_title)) },
                navigationIcon = {
                    IconButton(onClick = onBackClick) {
                        Icon(
                            Icons.AutoMirrored.Filled.ArrowBack,
                            contentDescription = stringResource(R.string.nav_back),
                        )
                    }
                },
            )
        },
        modifier = modifier,
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(Spacing.lg),
        ) {
            if (uiState.playerName != null) {
                ProfilePhotoRow(
                    name = uiState.playerName,
                    avatarUrl = uiState.avatarUrl,
                    isUploading = uiState.isUploadingAvatar,
                    onPickAvatar = onPickAvatar,
                    onRemoveAvatar = onRemoveAvatar,
                )
                uiState.avatarError?.let { error ->
                    ErrorText(
                        error = error,
                        errorKey = uiState.avatarErrorKey,
                        errorArgs = uiState.avatarErrorArgs,
                        modifier = Modifier.padding(top = Spacing.sm),
                    )
                }
                HorizontalDivider(modifier = Modifier.padding(vertical = Spacing.md))
            }
            LanguageDropdown(
                availableLocales = uiState.availableLocales,
                selectedLocaleTag = uiState.selectedLocaleTag,
                onLocaleSelected = onLocaleSelected,
            )
            ThemeDropdown(
                availableThemes = uiState.availableThemes,
                selectedTheme = uiState.selectedTheme,
                onThemeSelected = onThemeSelected,
                modifier = Modifier.padding(top = Spacing.md),
            )
            Spacer(Modifier.weight(1f))
            Text(
                text = stringResource(R.string.settings_version, versionName),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

@Composable
private fun ProfilePhotoPreview(name: String, avatarUrl: String?, isUploading: Boolean) {
    Box {
        ProfileAvatar(name = name, avatarUrl = avatarUrl, size = PROFILE_AVATAR_SIZE)
        if (isUploading) {
            Box(
                modifier = Modifier
                    .size(PROFILE_AVATAR_SIZE)
                    .clip(CircleShape)
                    .background(Color.Black.copy(alpha = UPLOAD_OVERLAY_ALPHA)),
                contentAlignment = Alignment.Center,
            ) {
                CircularProgressIndicator(
                    modifier = Modifier.size(24.dp),
                    color = Color.White,
                    strokeWidth = 2.dp,
                )
            }
        }
    }
}

@Composable
private fun ProfilePhotoRow(
    name: String,
    avatarUrl: String?,
    isUploading: Boolean,
    onPickAvatar: () -> Unit,
    onRemoveAvatar: () -> Unit,
) {
    Column {
        Row(verticalAlignment = Alignment.CenterVertically) {
            ProfilePhotoPreview(name = name, avatarUrl = avatarUrl, isUploading = isUploading)
            Column(
                modifier = Modifier
                    .weight(1f)
                    .padding(horizontal = Spacing.md),
            ) {
                Text(
                    text = stringResource(R.string.settings_profile_photo),
                    style = MaterialTheme.typography.titleMedium,
                )
                Text(
                    text = stringResource(
                        if (avatarUrl != null) {
                            R.string.settings_profile_photo_hint
                        } else {
                            R.string.settings_profile_photo_empty_hint
                        },
                    ),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            TextButton(onClick = onPickAvatar, enabled = !isUploading) {
                Text(
                    stringResource(
                        if (avatarUrl != null) {
                            R.string.settings_profile_photo_change
                        } else {
                            R.string.settings_profile_photo_add
                        },
                    ),
                )
            }
        }
        if (avatarUrl != null) {
            TextButton(
                onClick = onRemoveAvatar,
                enabled = !isUploading,
                colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error),
            ) {
                Text(stringResource(R.string.settings_profile_photo_remove))
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun LanguageDropdown(
    availableLocales: List<LocaleOption>,
    selectedLocaleTag: String?,
    onLocaleSelected: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    var dropdownExpanded by remember { mutableStateOf(false) }
    val selectedOption = availableLocales.find { locale ->
        selectedLocaleTag == locale.tag || (locale.tag.isEmpty() && selectedLocaleTag == null)
    }

    ExposedDropdownMenuBox(
        expanded = dropdownExpanded,
        onExpandedChange = { dropdownExpanded = it },
        modifier = modifier,
    ) {
        OutlinedTextField(
            value = selectedOption?.displayName?.resolve() ?: "",
            onValueChange = {},
            readOnly = true,
            label = { Text(stringResource(R.string.settings_language_label)) },
            trailingIcon = {
                ExposedDropdownMenuDefaults.TrailingIcon(expanded = dropdownExpanded)
            },
            modifier = Modifier
                .menuAnchor()
                .fillMaxWidth(),
        )
        ExposedDropdownMenu(
            expanded = dropdownExpanded,
            onDismissRequest = { dropdownExpanded = false },
        ) {
            availableLocales.forEach { locale ->
                DropdownMenuItem(
                    text = { Text(locale.displayName.resolve()) },
                    onClick = {
                        onLocaleSelected(locale.tag)
                        dropdownExpanded = false
                    },
                    contentPadding = ExposedDropdownMenuDefaults.ItemContentPadding,
                )
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ThemeDropdown(
    availableThemes: List<ThemeOption>,
    selectedTheme: String,
    onThemeSelected: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    var dropdownExpanded by remember { mutableStateOf(false) }
    val selectedOption = availableThemes.find { it.tag == selectedTheme }

    ExposedDropdownMenuBox(
        expanded = dropdownExpanded,
        onExpandedChange = { dropdownExpanded = it },
        modifier = modifier,
    ) {
        OutlinedTextField(
            value = selectedOption?.displayName?.resolve() ?: "",
            onValueChange = {},
            readOnly = true,
            label = { Text(stringResource(R.string.settings_theme_label)) },
            trailingIcon = {
                ExposedDropdownMenuDefaults.TrailingIcon(expanded = dropdownExpanded)
            },
            modifier = Modifier
                .menuAnchor()
                .fillMaxWidth(),
        )
        ExposedDropdownMenu(
            expanded = dropdownExpanded,
            onDismissRequest = { dropdownExpanded = false },
        ) {
            availableThemes.forEach { theme ->
                DropdownMenuItem(
                    text = { Text(theme.displayName.resolve()) },
                    onClick = {
                        onThemeSelected(theme.tag)
                        dropdownExpanded = false
                    },
                    contentPadding = ExposedDropdownMenuDefaults.ItemContentPadding,
                )
            }
        }
    }
}

@Preview
@Composable
private fun SettingsContentPreview() {
    AppTheme {
        SettingsContent(
            uiState = SettingsUiState(
                selectedLocaleTag = "en",
                availableLocales = defaultLocales,
                playerName = "Alice",
            ),
            versionName = "0.0.22",
            onLocaleSelected = {},
            onThemeSelected = {},
            onPickAvatar = {},
            onRemoveAvatar = {},
            onBackClick = {},
        )
    }
}
