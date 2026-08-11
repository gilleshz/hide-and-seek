package fr.gshz.hideandseek.feature.settings

import android.app.Activity
import android.widget.Toast
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.tooling.preview.Preview
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import fr.gshz.hideandseek.BuildConfig
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.ui.resolve
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing

@Composable
fun SettingsScreen(
    onBackClick: () -> Unit,
    viewModel: SettingsViewModel = hiltViewModel(),
    modifier: Modifier = Modifier,
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val context = LocalContext.current
    SettingsContent(
        uiState = uiState,
        versionName = BuildConfig.VERSION_NAME,
        onLocaleSelected = { tag ->
            viewModel.onLocaleSelected(tag)
            (context as? Activity)?.recreate()
        },
        onThemeSelected = viewModel::onThemeSelected,
        onBackClick = onBackClick,
        onCurrentPasswordChange = viewModel::onCurrentPasswordChange,
        onNewPasswordChange = viewModel::onNewPasswordChange,
        onChangePasswordClick = viewModel::changePassword,
        onPasswordChangedShown = viewModel::onPasswordChangedShown,
        modifier = modifier,
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
    onBackClick: () -> Unit,
    onCurrentPasswordChange: (String) -> Unit,
    onNewPasswordChange: (String) -> Unit,
    onChangePasswordClick: () -> Unit,
    onPasswordChangedShown: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    LaunchedEffect(uiState.passwordChanged) {
        if (uiState.passwordChanged) {
            Toast.makeText(context, R.string.settings_account_success, Toast.LENGTH_SHORT).show()
            onPasswordChangedShown()
        }
    }
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
            if (uiState.accountName != null) {
                AccountSection(
                    accountName = uiState.accountName,
                    currentPasswordInput = uiState.currentPasswordInput,
                    newPasswordInput = uiState.newPasswordInput,
                    isChangingPassword = uiState.isChangingPassword,
                    passwordError = uiState.passwordError,
                    passwordErrorKey = uiState.passwordErrorKey,
                    onCurrentPasswordChange = onCurrentPasswordChange,
                    onNewPasswordChange = onNewPasswordChange,
                    onChangePasswordClick = onChangePasswordClick,
                    modifier = Modifier.padding(bottom = Spacing.lg),
                )
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

@Suppress("LongParameterList")
@Composable
private fun AccountSection(
    accountName: String,
    currentPasswordInput: String,
    newPasswordInput: String,
    isChangingPassword: Boolean,
    passwordError: ErrorType?,
    passwordErrorKey: String?,
    onCurrentPasswordChange: (String) -> Unit,
    onNewPasswordChange: (String) -> Unit,
    onChangePasswordClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        modifier = modifier,
    ) {
        Text(
            text = stringResource(R.string.settings_account_section_title),
            style = MaterialTheme.typography.titleMedium,
        )
        Text(
            text = stringResource(R.string.settings_account_logged_in_as, accountName),
            style = MaterialTheme.typography.bodyMedium,
        )
        OutlinedTextField(
            value = currentPasswordInput,
            onValueChange = onCurrentPasswordChange,
            label = { Text(stringResource(R.string.settings_account_current_password_label)) },
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
            modifier = Modifier.fillMaxWidth(),
        )
        OutlinedTextField(
            value = newPasswordInput,
            onValueChange = onNewPasswordChange,
            label = { Text(stringResource(R.string.settings_account_new_password_label)) },
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
            modifier = Modifier.fillMaxWidth(),
        )
        Button(
            onClick = onChangePasswordClick,
            enabled = !isChangingPassword,
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text(stringResource(R.string.settings_account_save))
        }
        ErrorText(error = passwordError, errorKey = passwordErrorKey)
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
                accountName = "Alice",
            ),
            versionName = "0.0.22",
            onLocaleSelected = {},
            onThemeSelected = {},
            onBackClick = {},
            onCurrentPasswordChange = {},
            onNewPasswordChange = {},
            onChangePasswordClick = {},
            onPasswordChangedShown = {},
        )
    }
}
