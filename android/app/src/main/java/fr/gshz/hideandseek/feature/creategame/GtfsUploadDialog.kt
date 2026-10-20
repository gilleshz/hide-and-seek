package fr.gshz.hideandseek.feature.creategame

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.UiText
import fr.gshz.hideandseek.core.ui.resolve
import fr.gshz.hideandseek.core.ui.theme.Spacing

@Suppress("LongParameterList")
@Composable
internal fun GtfsUploadDialog(
    mode: GtfsDialogMode,
    urlInput: String,
    realtimeUrlInput: String,
    realtimeProbe: RealtimeProbeState?,
    isUploading: Boolean,
    error: UiText?,
    onModeChange: (GtfsDialogMode) -> Unit,
    onUrlChange: (String) -> Unit,
    onRealtimeUrlChange: (String) -> Unit,
    onUploadUrl: () -> Unit,
    onChooseFile: () -> Unit,
    onDismiss: () -> Unit,
) {
    Dialog(onDismissRequest = { if (!isUploading) onDismiss() }) {
        Surface(
            shape = MaterialTheme.shapes.large,
            color = MaterialTheme.colorScheme.surface,
            tonalElevation = 6.dp,
        ) {
            GtfsUploadDialogContent(
                mode = mode,
                urlInput = urlInput,
                realtimeUrlInput = realtimeUrlInput,
                realtimeProbe = realtimeProbe,
                isUploading = isUploading,
                error = error,
                onModeChange = onModeChange,
                onUrlChange = onUrlChange,
                onRealtimeUrlChange = onRealtimeUrlChange,
                onUploadUrl = onUploadUrl,
                onChooseFile = onChooseFile,
                onDismiss = onDismiss,
            )
        }
    }
}

@Suppress("LongParameterList")
@Composable
private fun GtfsUploadDialogContent(
    mode: GtfsDialogMode,
    urlInput: String,
    realtimeUrlInput: String,
    realtimeProbe: RealtimeProbeState?,
    isUploading: Boolean,
    error: UiText?,
    onModeChange: (GtfsDialogMode) -> Unit,
    onUrlChange: (String) -> Unit,
    onRealtimeUrlChange: (String) -> Unit,
    onUploadUrl: () -> Unit,
    onChooseFile: () -> Unit,
    onDismiss: () -> Unit,
) {
    Column(
        modifier = Modifier.padding(Spacing.lg),
        verticalArrangement = Arrangement.spacedBy(Spacing.md),
    ) {
        GtfsDialogTitleBar(isUploading = isUploading, onDismiss = onDismiss)
        GtfsModeSelector(mode = mode, isUploading = isUploading, onModeChange = onModeChange)
        GtfsRealtimeUrlField(
            urlInput = realtimeUrlInput,
            probeState = realtimeProbe,
            isUploading = isUploading,
            onUrlChange = onRealtimeUrlChange,
        )
        when (mode) {
            GtfsDialogMode.Url -> GtfsUrlTabContent(
                urlInput = urlInput,
                isUploading = isUploading,
                onUrlChange = onUrlChange,
                onUploadUrl = onUploadUrl,
            )
            GtfsDialogMode.File -> GtfsFileTabContent(
                isUploading = isUploading,
                onChooseFile = onChooseFile,
            )
        }
        if (error != null) {
            Text(
                text = error.resolve(),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.error,
            )
        }
    }
}

@Composable
private fun GtfsDialogTitleBar(isUploading: Boolean, onDismiss: () -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(
            text = stringResource(R.string.gtfs_dialog_title),
            style = MaterialTheme.typography.titleMedium,
        )
        if (!isUploading) {
            IconButton(onClick = onDismiss) {
                Icon(
                    Icons.Filled.Close,
                    contentDescription = stringResource(R.string.nav_back),
                )
            }
        }
    }
}

@Composable
private fun GtfsModeSelector(
    mode: GtfsDialogMode,
    isUploading: Boolean,
    onModeChange: (GtfsDialogMode) -> Unit,
) {
    SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
        SegmentedButton(
            selected = mode == GtfsDialogMode.Url,
            onClick = { onModeChange(GtfsDialogMode.Url) },
            enabled = !isUploading,
            shape = SegmentedButtonDefaults.itemShape(index = 0, count = 2),
        ) {
            Text(stringResource(R.string.gtfs_url_tab))
        }
        SegmentedButton(
            selected = mode == GtfsDialogMode.File,
            onClick = { onModeChange(GtfsDialogMode.File) },
            enabled = !isUploading,
            shape = SegmentedButtonDefaults.itemShape(index = 1, count = 2),
        ) {
            Text(stringResource(R.string.gtfs_file_tab))
        }
    }
}

@Composable
private fun GtfsRealtimeUrlField(
    urlInput: String,
    probeState: RealtimeProbeState?,
    isUploading: Boolean,
    onUrlChange: (String) -> Unit,
) {
    val supporting: (@Composable () -> Unit)? = probeState?.let { probe ->
        {
            Text(
                text = realtimeProbeText(probe),
                style = MaterialTheme.typography.bodySmall,
                color = realtimeProbeColor(probe),
            )
        }
    }
    OutlinedTextField(
        value = urlInput,
        onValueChange = onUrlChange,
        label = { Text(stringResource(R.string.gtfs_realtime_url_label)) },
        placeholder = { Text(stringResource(R.string.gtfs_realtime_url_hint)) },
        supportingText = supporting,
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
        enabled = !isUploading,
        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
    )
}

@Composable
private fun realtimeProbeText(probe: RealtimeProbeState): String = when (probe) {
    RealtimeProbeState.Checking -> stringResource(R.string.gtfs_realtime_probe_checking)
    is RealtimeProbeState.Ok -> if (probe.ageSeconds != null) {
        stringResource(
            R.string.gtfs_realtime_probe_ok_age,
            probe.tripCount,
            probeAgeLabel(probe.ageSeconds),
        )
    } else {
        stringResource(R.string.gtfs_realtime_probe_ok, probe.tripCount)
    }
    is RealtimeProbeState.Stale -> stringResource(
        R.string.gtfs_realtime_probe_stale,
        probeAgeLabel(probe.ageSeconds),
    )
    is RealtimeProbeState.WrongType -> stringResource(R.string.gtfs_realtime_probe_wrong_type)
    RealtimeProbeState.Invalid -> stringResource(R.string.gtfs_realtime_probe_invalid)
    RealtimeProbeState.Unreachable -> stringResource(R.string.gtfs_realtime_probe_unreachable)
    RealtimeProbeState.InvalidUrl -> stringResource(R.string.gtfs_realtime_probe_invalid_url)
}

@Composable
private fun realtimeProbeColor(probe: RealtimeProbeState) = when (probe) {
    RealtimeProbeState.Checking, is RealtimeProbeState.Stale -> MaterialTheme.colorScheme.onSurfaceVariant
    is RealtimeProbeState.Ok -> MaterialTheme.colorScheme.primary
    else -> MaterialTheme.colorScheme.error
}

@Composable
private fun probeAgeLabel(seconds: Int): String =
    if (seconds < SECONDS_PER_MINUTE) {
        stringResource(R.string.gtfs_realtime_probe_seconds, seconds)
    } else {
        stringResource(R.string.gtfs_realtime_probe_minutes, seconds / SECONDS_PER_MINUTE)
    }

private const val SECONDS_PER_MINUTE = 60

@Composable
private fun GtfsUrlTabContent(
    urlInput: String,
    isUploading: Boolean,
    onUrlChange: (String) -> Unit,
    onUploadUrl: () -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.md)) {
        OutlinedTextField(
            value = urlInput,
            onValueChange = onUrlChange,
            label = { Text(stringResource(R.string.gtfs_url_label)) },
            placeholder = { Text(stringResource(R.string.gtfs_url_hint)) },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            enabled = !isUploading,
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Go),
            keyboardActions = KeyboardActions(onGo = { onUploadUrl() }),
        )
        UploadButton(
            enabled = !isUploading && urlInput.isNotBlank(),
            isUploading = isUploading,
            label = stringResource(R.string.gtfs_upload_url),
            onClick = onUploadUrl,
        )
    }
}

@Composable
private fun GtfsFileTabContent(
    isUploading: Boolean,
    onChooseFile: () -> Unit,
) {
    OutlinedButton(
        onClick = onChooseFile,
        enabled = !isUploading,
        modifier = Modifier.fillMaxWidth(),
    ) {
        if (isUploading) {
            CircularProgressIndicator(
                modifier = Modifier.size(20.dp),
                strokeWidth = 2.dp,
            )
            Spacer(Modifier.width(Spacing.sm))
            Text(stringResource(R.string.gtfs_uploading))
        } else {
            Text(stringResource(R.string.gtfs_choose_file))
        }
    }
}

@Composable
private fun UploadButton(
    enabled: Boolean,
    isUploading: Boolean,
    label: String,
    onClick: () -> Unit,
) {
    Button(
        onClick = onClick,
        enabled = enabled,
        modifier = Modifier.fillMaxWidth(),
    ) {
        if (isUploading) {
            CircularProgressIndicator(
                modifier = Modifier.size(20.dp),
                color = MaterialTheme.colorScheme.onPrimary,
                strokeWidth = 2.dp,
            )
            Spacer(Modifier.width(Spacing.sm))
            Text(stringResource(R.string.gtfs_uploading))
        } else {
            Text(label)
        }
    }
}
