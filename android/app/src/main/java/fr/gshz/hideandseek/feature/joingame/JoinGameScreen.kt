package fr.gshz.hideandseek.feature.joingame

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Login
import androidx.compose.material.icons.filled.Map
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing

@Composable
fun JoinGameScreen(
    onJoined: (gameUuid: String) -> Unit,
    onNeedAccount: (errorKey: String?) -> Unit,
    onScanClick: () -> Unit = {},
    viewModel: JoinGameViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()

    LaunchedEffect(uiState.joinedGameUuid) {
        uiState.joinedGameUuid?.let(onJoined)
    }

    LaunchedEffect(uiState.needsAccount) {
        if (uiState.needsAccount) onNeedAccount(uiState.needAccountErrorKey)
    }

    JoinGameContent(
        uiState = uiState,
        onGameKeyChange = viewModel::onGameKeyChange,
        onJoinClick = viewModel::join,
        onScanClick = onScanClick,
    )
}

@Composable
internal fun JoinGameContent(
    uiState: JoinGameUiState,
    onGameKeyChange: (String) -> Unit,
    onJoinClick: () -> Unit,
    onScanClick: () -> Unit = {},
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .statusBarsPadding()
            .navigationBarsPadding()
            .verticalScroll(rememberScrollState())
            .padding(Spacing.lg),
        verticalArrangement = Arrangement.spacedBy(Spacing.lg),
    ) {
        ScreenTitle()

        GameKeyField(
            value = uiState.gameKey,
            onValueChange = onGameKeyChange,
            onScanClick = onScanClick,
            onJoin = onJoinClick,
        )

        ErrorText(uiState.error, uiState.errorDetail, uiState.errorKey, uiState.errorArgs)

        JoinGameButton(isLoading = uiState.isLoading, onClick = onJoinClick)
    }
}

@Composable
private fun GameKeyField(
    value: String,
    onValueChange: (String) -> Unit,
    onScanClick: () -> Unit,
    onJoin: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        modifier = modifier,
    ) {
        OutlinedTextField(
            value = value,
            onValueChange = onValueChange,
            label = { Text(stringResource(R.string.join_game_key_label)) },
            textStyle = MaterialTheme.typography.headlineSmall,
            modifier = Modifier.weight(1f),
            singleLine = true,
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Done),
            keyboardActions = KeyboardActions(onDone = { onJoin() }),
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
private fun ScreenTitle(modifier: Modifier = Modifier) {
    Row(
        modifier = modifier,
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        Icon(
            imageVector = Icons.Filled.Map,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.primary,
        )
        Text(
            text = stringResource(R.string.join_game_title),
            style = MaterialTheme.typography.headlineSmall,
        )
    }
}

@Composable
private fun JoinGameButton(isLoading: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier) {
    Button(onClick = onClick, enabled = !isLoading, modifier = modifier.fillMaxWidth()) {
        if (isLoading) {
            CircularProgressIndicator(
                modifier = Modifier.size(20.dp),
                color = MaterialTheme.colorScheme.onPrimary,
                strokeWidth = 2.dp,
            )
        } else {
            Icon(
                imageVector = Icons.AutoMirrored.Filled.Login,
                contentDescription = null,
                modifier = Modifier.size(20.dp),
            )
            Spacer(modifier = Modifier.width(Spacing.sm))
            Text(text = stringResource(R.string.join_game_button), style = MaterialTheme.typography.titleMedium)
        }
    }
}

@androidx.compose.ui.tooling.preview.Preview(showBackground = true)
@Composable
private fun JoinGameContentPreview() {
    AppTheme {
        JoinGameContent(
            uiState = JoinGameUiState(gameKey = "1234"),
            onGameKeyChange = {},
            onJoinClick = {},
            onScanClick = {},
        )
    }
}
