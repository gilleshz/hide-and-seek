package fr.gshz.hideandseek.feature.connect

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Link
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusDirection
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.BrandWordmark
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing

@Composable
fun ConnectScreen(
    onConnected: () -> Unit,
    onScanJoin: (String) -> Unit,
    onScanClick: () -> Unit = {},
    onOpenSettings: () -> Unit = {},
    viewModel: ConnectViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()

    LaunchedEffect(uiState.connected) {
        if (uiState.connected) onConnected()
    }

    LaunchedEffect(uiState.scannedGameCode) {
        uiState.scannedGameCode?.let(onScanJoin)
    }

    ConnectContent(
        uiState = uiState,
        onApiUrlChange = viewModel::onApiUrlChange,
        onApiKeyChange = viewModel::onApiKeyChange,
        onDisplayNameChange = viewModel::onDisplayNameChange,
        onPasswordChange = viewModel::onPasswordChange,
        onConnectClick = viewModel::connect,
        onScanClick = onScanClick,
        onOpenSettings = onOpenSettings,
    )
}

@Suppress("LongParameterList")
@Composable
internal fun ConnectContent(
    uiState: ConnectUiState,
    onApiUrlChange: (String) -> Unit,
    onApiKeyChange: (String) -> Unit,
    onDisplayNameChange: (String) -> Unit,
    onPasswordChange: (String) -> Unit,
    onConnectClick: () -> Unit,
    onScanClick: () -> Unit = {},
    onOpenSettings: () -> Unit = {},
    modifier: Modifier = Modifier,
) {
    Box(
        modifier = modifier
            .fillMaxSize()
            .statusBarsPadding()
            .navigationBarsPadding(),
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(Spacing.lg),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(Spacing.xl, Alignment.CenterVertically),
        ) {
            BrandWordmark()

            Text(
                text = stringResource(R.string.connect_subtitle),
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )

            ConnectForm(
                uiState = uiState,
                onApiUrlChange = onApiUrlChange,
                onApiKeyChange = onApiKeyChange,
                onDisplayNameChange = onDisplayNameChange,
                onPasswordChange = onPasswordChange,
                onConnectClick = onConnectClick,
                onScanClick = onScanClick,
            )
        }

        ConnectSettingsButton(
            onClick = onOpenSettings,
            modifier = Modifier
                .align(Alignment.TopEnd)
                .padding(Spacing.sm),
        )
    }
}

@Composable
private fun ConnectSettingsButton(
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    IconButton(onClick = onClick, modifier = modifier) {
        Icon(
            imageVector = Icons.Filled.Settings,
            contentDescription = stringResource(R.string.settings_title),
        )
    }
}

@Suppress("LongParameterList", "LongMethod")
@Composable
private fun ConnectForm(
    uiState: ConnectUiState,
    onApiUrlChange: (String) -> Unit,
    onApiKeyChange: (String) -> Unit,
    onDisplayNameChange: (String) -> Unit,
    onPasswordChange: (String) -> Unit,
    onConnectClick: () -> Unit,
    onScanClick: () -> Unit = {},
) {
    val focusManager = LocalFocusManager.current
    Surface(
        modifier = Modifier.fillMaxWidth(),
        color = MaterialTheme.colorScheme.surfaceContainer,
        shape = MaterialTheme.shapes.medium,
    ) {
        Column(
            modifier = Modifier.padding(Spacing.lg),
            verticalArrangement = Arrangement.spacedBy(Spacing.md),
        ) {
            ApiUrlRow(
                value = uiState.apiUrl,
                onValueChange = onApiUrlChange,
                onScanClick = onScanClick,
                onNext = { focusManager.moveFocus(FocusDirection.Down) },
            )

            if (uiState.isPlainHttp) {
                Text(
                    text = stringResource(R.string.connect_plain_http_warning),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.error,
                )
            }

            ApiKeyField(
                value = uiState.apiKey,
                onValueChange = onApiKeyChange,
                onNext = { focusManager.moveFocus(FocusDirection.Down) },
            )

            AccountFields(
                displayName = uiState.displayName,
                passwordInput = uiState.passwordInput,
                onDisplayNameChange = onDisplayNameChange,
                onPasswordChange = onPasswordChange,
                onConnectClick = onConnectClick,
            )

            Text(
                text = stringResource(R.string.connect_account_helper),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            ErrorText(uiState.error, errorKey = uiState.errorKey)

            ConnectButton(
                isConnecting = uiState.isConnecting,
                onClick = onConnectClick,
            )

            Text(
                text = stringResource(R.string.connect_location_shared),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun ApiKeyField(
    value: String,
    onValueChange: (String) -> Unit,
    onNext: () -> Unit,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(stringResource(R.string.connect_api_key_label)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
        keyboardActions = KeyboardActions(onNext = { onNext() }),
    )
}

@Composable
private fun AccountFields(
    displayName: String,
    passwordInput: String,
    onDisplayNameChange: (String) -> Unit,
    onPasswordChange: (String) -> Unit,
    onConnectClick: () -> Unit,
) {
    val focusManager = LocalFocusManager.current

    OutlinedTextField(
        value = displayName,
        onValueChange = onDisplayNameChange,
        label = { Text(stringResource(R.string.connect_account_name_label)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
        keyboardActions = KeyboardActions(onNext = { focusManager.moveFocus(FocusDirection.Down) }),
    )

    OutlinedTextField(
        value = passwordInput,
        onValueChange = onPasswordChange,
        label = { Text(stringResource(R.string.connect_account_password_label)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
        visualTransformation = PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
        keyboardActions = KeyboardActions(onDone = { onConnectClick() }),
    )
}

@Composable
private fun ApiUrlRow(
    value: String,
    onValueChange: (String) -> Unit,
    onScanClick: () -> Unit,
    onNext: () -> Unit,
) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        OutlinedTextField(
            value = value,
            onValueChange = onValueChange,
            label = { Text(stringResource(R.string.connect_api_url_label)) },
            modifier = Modifier.weight(1f),
            singleLine = true,
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
            keyboardActions = KeyboardActions(onNext = { onNext() }),
        )
        IconButton(onClick = onScanClick) {
            Icon(
                imageVector = Icons.Filled.QrCodeScanner,
                contentDescription = stringResource(R.string.qr_scan_button),
                modifier = Modifier.size(32.dp),
                tint = MaterialTheme.colorScheme.primary,
            )
        }
    }
}

@Composable
private fun ConnectButton(isConnecting: Boolean, onClick: () -> Unit) {
    Button(
        onClick = onClick,
        enabled = !isConnecting,
        modifier = Modifier.fillMaxWidth(),
    ) {
        if (isConnecting) {
            CircularProgressIndicator(
                modifier = Modifier.size(20.dp),
                color = MaterialTheme.colorScheme.onPrimary,
            )
        } else {
            Icon(imageVector = Icons.Filled.Link, contentDescription = null)
            Text(
                text = stringResource(R.string.connect_button),
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier.padding(start = Spacing.sm),
            )
        }
    }
}

@androidx.compose.ui.tooling.preview.Preview(showBackground = true)
@Composable
private fun ConnectContentPreview() {
    AppTheme {
        ConnectContent(
            uiState = ConnectUiState(
                apiUrl = "https://example.com",
                apiKey = "secret",
                displayName = "Alice",
                passwordInput = "hunter2",
            ),
            onApiUrlChange = {},
            onApiKeyChange = {},
            onDisplayNameChange = {},
            onPasswordChange = {},
            onConnectClick = {},
        )
    }
}
