package fr.gshz.hideandseek.feature.lobby

import android.Manifest
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Groups
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Route
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Map
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.location.LocationTrackingService
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.ui.QrCode
import fr.gshz.hideandseek.core.ui.RoleBadge
import fr.gshz.hideandseek.core.ui.SectionHeader
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.BrandColors
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.LeaderboardEntry
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.Side

@Suppress("LongMethod")
@Composable
fun LobbyScreen(
    onOpenMapClick: (gameUuid: String) -> Unit,
    onNavigateHome: () -> Unit = {},
    onOpenSettingsClick: () -> Unit = {},
    viewModel: LobbyViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    DisposableEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME) viewModel.onScreenResumed()
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    // The lobby is the back-stack root: consume back so it never closes the app; the map needs a side.
    BackHandler { if (uiState.mySide != null) onOpenMapClick(uiState.gameUuid) }
    var backgroundLocationMissing by remember { mutableStateOf(false) }
    var trackingStarted by remember { mutableStateOf(false) }

    fun startTracking() {
        ContextCompat.startForegroundService(context, Intent(context, LocationTrackingService::class.java))
        trackingStarted = true
    }

    val backgroundPermissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission(),
    ) { granted ->
        backgroundLocationMissing = !granted
        startTracking()
    }

    val foregroundPermissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestMultiplePermissions(),
    ) { grants ->
        if (grants[Manifest.permission.ACCESS_FINE_LOCATION] == true) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                backgroundPermissionLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
            } else {
                startTracking()
            }
        }
    }

    LaunchedEffect(uiState.mySide) {
        if (uiState.mySide != null && !trackingStarted) {
            foregroundPermissionLauncher.launch(foregroundLocationPermissions())
        }
    }

    LaunchedEffect(uiState.navigatedHome) {
        if (uiState.navigatedHome) {
            stopLocationTracking(context)
            viewModel.onNavigatedHome()
            onNavigateHome()
        }
    }

    LobbyContent(
        uiState = uiState,
        actions = LobbyActions(
            onSideClick = viewModel::chooseSide,
            onStartRoundClick = viewModel::startRound,
            onOpenMapClick = { onOpenMapClick(uiState.gameUuid) },
            onHidingTimeChanged = viewModel::onHidingTimeChanged,
            onCreateNewRoundClick = viewModel::createNewRound,
            onStopRoundClick = viewModel::stopRound,
            onLeaveGameClick = viewModel::leaveGame,
            onDeleteGameClick = viewModel::deleteGame,
            onOpenSettingsClick = onOpenSettingsClick,
            onPlayerLongPress = viewModel::onPlayerLongPress,
            onDismissPlayerMenu = viewModel::dismissPlayerMenu,
            onRemovePlayerClick = viewModel::onRemovePlayerClick,
            onConfirmRemovePlayerClick = viewModel::confirmRemovePlayer,
            onDismissRemovePlayerConfirm = viewModel::dismissRemovePlayerConfirm,
        ),
        showBackgroundLocationHint = backgroundLocationMissing,
    )
}

internal data class LobbyActions(
    val onSideClick: (Side) -> Unit,
    val onStartRoundClick: () -> Unit,
    val onOpenMapClick: () -> Unit,
    val onHidingTimeChanged: (String) -> Unit = {},
    val onCreateNewRoundClick: () -> Unit = {},
    val onStopRoundClick: () -> Unit = {},
    val onLeaveGameClick: () -> Unit = {},
    val onDeleteGameClick: () -> Unit = {},
    val onOpenSettingsClick: () -> Unit = {},
    val onPlayerLongPress: (Player) -> Unit = {},
    val onDismissPlayerMenu: () -> Unit = {},
    val onRemovePlayerClick: (Player) -> Unit = {},
    val onConfirmRemovePlayerClick: () -> Unit = {},
    val onDismissRemovePlayerConfirm: () -> Unit = {},
)

private fun stopLocationTracking(context: Context) {
    context.startService(
        Intent(context, LocationTrackingService::class.java)
            .setAction(LocationTrackingService.ACTION_STOP),
    )
}

private fun foregroundLocationPermissions(): Array<String> {
    val permissions = mutableListOf(Manifest.permission.ACCESS_FINE_LOCATION)
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
        permissions += Manifest.permission.POST_NOTIFICATIONS
    }
    return permissions.toTypedArray()
}

@Suppress("LongMethod")
@Composable
internal fun LobbyContent(
    uiState: LobbyUiState,
    actions: LobbyActions,
    showBackgroundLocationHint: Boolean = false,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .statusBarsPadding()
            .navigationBarsPadding()
            .verticalScroll(rememberScrollState())
            .padding(Spacing.lg),
        verticalArrangement = Arrangement.spacedBy(Spacing.md),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                text = uiState.gameName.ifBlank { stringResource(R.string.lobby_title) },
                style = MaterialTheme.typography.headlineSmall,
                modifier = Modifier.weight(1f),
            )
            IconButton(onClick = actions.onOpenSettingsClick) {
                Icon(
                    Icons.Filled.Settings,
                    contentDescription = stringResource(R.string.settings_title),
                )
            }
        }

        ErrorText(
            uiState.error,
            detail = uiState.errorDetail,
            errorKey = uiState.errorKey,
            errorArgs = uiState.errorArgs,
        )

        var showQr by remember { mutableStateOf(false) }
        GameCodeCard(
            code = uiState.gameJoinCode,
            isQrVisible = showQr,
            onToggleQrClick = { showQr = !showQr },
        )

        if (showQr && uiState.qrPayload != null) {
            QrCode(
                content = uiState.qrPayload,
                size = 180.dp,
                modifier = Modifier.align(Alignment.CenterHorizontally),
            )
        }

        if (uiState.hasLeaderboard) {
            LeaderboardSection(entries = uiState.leaderboard)
        }

        if (uiState.canStartRound) {
            HidingTimeField(value = uiState.hidingTimeMinutesInput, onValueChange = actions.onHidingTimeChanged)
            StartRoundButton(
                isStarting = uiState.isStartingRound,
                allSidesChosen = uiState.allPlayersChoseSide,
                onClick = actions.onStartRoundClick,
            )
        }

        if (uiState.canCreateNewRound) {
            Button(
                onClick = actions.onCreateNewRoundClick,
                enabled = !uiState.isCreatingRound,
                modifier = Modifier.fillMaxWidth(),
            ) {
                if (uiState.isCreatingRound) {
                    CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
                } else {
                    Text(stringResource(R.string.lobby_new_round))
                }
            }
        }

        if (uiState.canStopRound) {
            FilledTonalButton(
                onClick = actions.onStopRoundClick,
                enabled = !uiState.isStoppingRound,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.lobby_stop_round))
            }
        }

        SectionHeader(text = stringResource(R.string.lobby_pick_side_title), icon = Icons.Filled.Route)
        SidePicker(selected = uiState.mySide, onSideClick = actions.onSideClick)

        if (uiState.mySide != null) {
            OpenMapButton(onOpenMapClick = actions.onOpenMapClick, showHint = showBackgroundLocationHint)
        }

        SectionHeader(text = stringResource(R.string.lobby_roster_title), icon = Icons.Filled.Groups)
        if (uiState.isLoading) {
            CircularProgressIndicator()
        } else {
            RosterList(
                roster = uiState.roster,
                onPlayerLongPress = actions.onPlayerLongPress,
            )
        }

        GameActionButtons(uiState = uiState, actions = actions)
    }

    uiState.playerMenuTarget?.let { target ->
        PlayerActionsSheet(
            playerName = target.displayName,
            onRemovePlayer = { actions.onRemovePlayerClick(target) },
            onDismiss = actions.onDismissPlayerMenu,
        )
    }

    uiState.removeConfirmTarget?.let { target ->
        RemovePlayerDialog(
            state = RemovePlayerDialogState(
                targetName = target.displayName,
                isRemoving = uiState.isRemovingPlayer,
                error = uiState.removePlayerError,
                errorKey = uiState.removePlayerErrorKey,
                errorArgs = null,
            ),
            onConfirm = actions.onConfirmRemovePlayerClick,
            onDismiss = actions.onDismissRemovePlayerConfirm,
        )
    }
}

@Composable
private fun GameActionButtons(uiState: LobbyUiState, actions: LobbyActions) {
    var showDeleteConfirm by remember { mutableStateOf(false) }

    Spacer(Modifier.height(Spacing.sm))

    OutlinedButton(
        onClick = actions.onLeaveGameClick,
        enabled = !uiState.isLeaving && !uiState.isDeleting,
        modifier = Modifier.fillMaxWidth(),
    ) {
        if (uiState.isLeaving) {
            CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
        } else {
            Text(stringResource(R.string.lobby_leave_game))
        }
    }

    if (uiState.isHost) {
        OutlinedButton(
            onClick = { showDeleteConfirm = true },
            enabled = !uiState.isLeaving && !uiState.isDeleting,
            colors = ButtonDefaults.outlinedButtonColors(contentColor = MaterialTheme.colorScheme.error),
            border = BorderStroke(1.dp, MaterialTheme.colorScheme.error),
            modifier = Modifier.fillMaxWidth(),
        ) {
            if (uiState.isDeleting) {
                CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
            } else {
                Text(stringResource(R.string.lobby_delete_game))
            }
        }
    }

    if (showDeleteConfirm) {
        DeleteGameDialog(
            onConfirm = {
                showDeleteConfirm = false
                actions.onDeleteGameClick()
            },
            onDismiss = { showDeleteConfirm = false },
        )
    }
}

@Composable
private fun DeleteGameDialog(onConfirm: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.lobby_delete_game_confirm_title)) },
        text = { Text(stringResource(R.string.lobby_delete_game_confirm_body)) },
        confirmButton = {
            TextButton(
                onClick = onConfirm,
                colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error),
            ) {
                Text(stringResource(R.string.lobby_delete_game_confirm))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text(stringResource(R.string.lobby_ingest_features_dialog_cancel))
            }
        },
    )
}

@Composable
private fun SidePicker(selected: Side?, onSideClick: (Side) -> Unit, modifier: Modifier = Modifier) {
    Row(
        modifier = modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        Side.entries.forEach { side ->
            SideChoiceCard(
                side = side,
                selected = selected == side,
                onClick = { onSideClick(side) },
                modifier = Modifier.weight(1f),
            )
        }
    }
}

@Composable
private fun SideChoiceCard(side: Side, selected: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier) {
    val accent = if (side == Side.Hider) BrandColors.hider else BrandColors.seeker
    val onAccent = if (side == Side.Hider) BrandColors.onHider else BrandColors.onSeeker
    val container = if (selected) accent else MaterialTheme.colorScheme.surfaceContainerHigh
    val content = if (selected) onAccent else MaterialTheme.colorScheme.onSurfaceVariant
    Surface(
        onClick = onClick,
        modifier = modifier,
        shape = MaterialTheme.shapes.medium,
        color = container,
        contentColor = content,
        border = if (selected) null else BorderStroke(1.dp, accent),
    ) {
        Column(
            modifier = Modifier.padding(vertical = Spacing.md, horizontal = Spacing.sm),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(Spacing.xs),
        ) {
            Icon(imageVector = side.icon(), contentDescription = null, modifier = Modifier.size(28.dp))
            Row(
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
            ) {
                Text(text = stringResource(side.labelRes()), style = MaterialTheme.typography.titleMedium)
                if (selected) {
                    Icon(imageVector = Icons.Filled.Check, contentDescription = null, modifier = Modifier.size(18.dp))
                }
            }
        }
    }
}


private fun Side.labelRes() = if (this == Side.Hider) R.string.lobby_side_hider else R.string.lobby_side_seeker

private fun Side.icon(): ImageVector =
    if (this == Side.Hider) Icons.Filled.VisibilityOff else Icons.Filled.Visibility

@androidx.compose.ui.tooling.preview.Preview(showBackground = true)
@Composable
private fun LobbyContentPreview() {
    AppTheme {
        LobbyContent(
            uiState = LobbyUiState(
                gameName = "Berlin",
                gameUuid = "BER-4821",
                gameJoinCode = "CXUW37",
                roster = listOf(
                    Player("1", "Alice", Side.Seeker),
                    Player("2", "Bob", Side.Hider),
                    Player("3", "Carol"),
                ),
                mySide = Side.Seeker,
                isLoading = false,
                leaderboard = listOf(
                    LeaderboardEntry("r3", 3, listOf("Bob", "Carol"), 4200L, 8040L, 12, 25),
                    LeaderboardEntry("r1", 1, listOf("Alice"), 3600L, 6300L, 0, 10),
                    LeaderboardEntry("r2", 2, emptyList(), 2400L, 2400L, 0, 0),
                ),
            ),
            actions = LobbyActions(onSideClick = {}, onStartRoundClick = {}, onOpenMapClick = {}),
        )
    }
}
