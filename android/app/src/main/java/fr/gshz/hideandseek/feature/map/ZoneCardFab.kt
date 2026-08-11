package fr.gshz.hideandseek.feature.map

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Style
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.ImageSourceDialog
import fr.gshz.hideandseek.core.ui.newCameraOutputUri
import fr.gshz.hideandseek.domain.model.ZoneCard

/**
 * Once the seekers are hunting, the zone changes only by playing one of the three cards,
 * and only with a photo of the card; the rule veto and randomize still apply.
 */
@Composable
internal fun ZoneCardFab(
    uiState: MapUiState,
    onPlayZoneCard: (ZoneCard, String) -> Unit,
    onEnterTimeTrapPlacement: () -> Unit,
) {
    val expanded = remember { mutableStateOf(false) }
    // The camera can kill the activity, so the pending card must survive recreation.
    val pendingCard = rememberSaveable { mutableStateOf<ZoneCard?>(null) }
    val cardNeedingConfirmation = remember { mutableStateOf<ZoneCard?>(null) }

    val picker = rememberCardPhotoPicker(
        onPicked = { uri ->
            pendingCard.value?.let { onPlayZoneCard(it, uri) }
            pendingCard.value = null
            expanded.value = false
        },
        onCancelled = { pendingCard.value = null },
    )
    val play: (ZoneCard) -> Unit = { card ->
        pendingCard.value = card
        picker()
    }

    Column(verticalArrangement = Arrangement.spacedBy(8.dp), horizontalAlignment = Alignment.End) {
        if (expanded.value) {
            ZoneCardActions(
                showZoneCards = uiState.canPlayZoneCards,
                onSelect = { card ->
                    // Tiny Home may not leave the hider outside the halved zone, so warn before playing it.
                    if (card == ZoneCard.TinyHome && uiState.halfZoneExcludesSelf) {
                        cardNeedingConfirmation.value = card
                    } else {
                        play(card)
                    }
                },
                onTimeTrap = {
                    expanded.value = false
                    onEnterTimeTrapPlacement()
                },
            )
        }
        ZoneCardMenuToggle(expanded = expanded.value, onToggle = { expanded.value = !expanded.value })
    }

    cardNeedingConfirmation.value?.let { card ->
        TinyHomeWarningDialog(
            onConfirm = {
                cardNeedingConfirmation.value = null
                play(card)
            },
            onDismiss = { cardNeedingConfirmation.value = null },
        )
    }
}

@Composable
private fun ZoneCardMenuToggle(expanded: Boolean, onToggle: () -> Unit) {
    FloatingActionButton(onClick = onToggle) {
        Icon(
            if (expanded) Icons.Filled.Close else Icons.Filled.Style,
            contentDescription = stringResource(
                if (expanded) R.string.zone_card_menu_close else R.string.zone_card_menu,
            ),
        )
    }
}

/** The three zone cards need an existing zone; the trap does not, so it is always offered. */
@Composable
private fun ZoneCardActions(showZoneCards: Boolean, onSelect: (ZoneCard) -> Unit, onTimeTrap: () -> Unit) {
    ExtendedFloatingActionButton(
        onClick = onTimeTrap,
        text = { Text(stringResource(R.string.time_trap_menu)) },
        icon = {},
    )
    if (!showZoneCards) return
    ExtendedFloatingActionButton(
        onClick = { onSelect(ZoneCard.ProsperousHome) },
        text = { Text(stringResource(R.string.zone_card_prosperous_home)) },
        icon = {},
    )
    ExtendedFloatingActionButton(
        onClick = { onSelect(ZoneCard.TinyHome) },
        text = { Text(stringResource(R.string.zone_card_tiny_home)) },
        icon = {},
    )
    ExtendedFloatingActionButton(
        onClick = { onSelect(ZoneCard.Move) },
        text = { Text(stringResource(R.string.zone_card_move)) },
        icon = {},
    )
}

@Composable
private fun TinyHomeWarningDialog(onConfirm: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.zone_card_tiny_home_warning_title)) },
        text = { Text(stringResource(R.string.zone_card_tiny_home_warning)) },
        confirmButton = {
            TextButton(onClick = onConfirm) { Text(stringResource(R.string.zone_card_play_anyway)) }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(android.R.string.cancel)) }
        },
    )
}

/**
 * Callback that asks for the card photo, camera or gallery, and hands back its URI.
 * Cancelling plays nothing.
 */
@Composable
internal fun rememberCardPhotoPicker(onPicked: (String) -> Unit, onCancelled: () -> Unit): () -> Unit {
    val showSource = rememberSaveable { mutableStateOf(false) }
    val pendingCameraUri = rememberSaveable { mutableStateOf<Uri?>(null) }
    val context = LocalContext.current
    val consume: (Uri?) -> Unit = { uri -> if (uri == null) onCancelled() else onPicked(uri.toString()) }

    val galleryLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.PickVisualMedia(),
    ) { uri -> consume(uri) }
    val cameraLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.TakePicture(),
    ) { isSuccess ->
        val uri = pendingCameraUri.value
        pendingCameraUri.value = null
        consume(if (isSuccess) uri else null)
    }

    if (showSource.value) {
        ImageSourceDialog(
            onCameraClick = {
                showSource.value = false
                val uri = newCameraOutputUri(context)
                pendingCameraUri.value = uri
                cameraLauncher.launch(uri)
            },
            onGalleryClick = {
                showSource.value = false
                galleryLauncher.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
            },
            onDismiss = {
                showSource.value = false
                onCancelled()
            },
            titleRes = R.string.zone_card_photo_title,
            messageRes = R.string.zone_card_photo_message,
        )
    }

    return { showSource.value = true }
}
