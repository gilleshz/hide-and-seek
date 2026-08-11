package fr.gshz.hideandseek.feature.home

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Login
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.AddLocationAlt
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Button
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.BrandWordmark
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing

@Composable
fun HomeScreen(
    onCreateGameClick: () -> Unit,
    onJoinGameClick: () -> Unit,
    onChangeServerClick: () -> Unit,
    onSettingsClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier = modifier
            .fillMaxSize()
            .statusBarsPadding()
            .navigationBarsPadding(),
    ) {
        HomeScreenContent(
            onCreateGameClick = onCreateGameClick,
            onJoinGameClick = onJoinGameClick,
            onChangeServerClick = onChangeServerClick,
        )
        SettingsIconButton(
            onClick = onSettingsClick,
            modifier = Modifier
                .align(Alignment.TopEnd)
                .padding(Spacing.sm),
        )
    }
}

@Composable
private fun SettingsIconButton(onClick: () -> Unit, modifier: Modifier = Modifier) {
    IconButton(onClick = onClick, modifier = modifier) {
        Icon(
            imageVector = Icons.Filled.Settings,
            contentDescription = stringResource(R.string.settings_title),
        )
    }
}

@Composable
private fun HomeScreenContent(
    onCreateGameClick: () -> Unit,
    onJoinGameClick: () -> Unit,
    onChangeServerClick: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(Spacing.lg),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(Spacing.xl, Alignment.CenterVertically),
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            BrandWordmark()

            Text(
                text = stringResource(R.string.home_tagline),
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.primary,
                textAlign = TextAlign.Center,
            )
        }

        Column(
            modifier = Modifier.fillMaxWidth(),
            verticalArrangement = Arrangement.spacedBy(Spacing.md),
        ) {
            HomeButton(
                onClick = onCreateGameClick,
                icon = Icons.Filled.AddLocationAlt,
                label = stringResource(R.string.home_create_game),
                isPrimary = true,
            )
            HomeButton(
                onClick = onJoinGameClick,
                icon = Icons.AutoMirrored.Filled.Login,
                label = stringResource(R.string.home_join_game),
                isPrimary = false,
            )
            HomeButton(
                onClick = onChangeServerClick,
                icon = Icons.AutoMirrored.Filled.Logout,
                label = stringResource(R.string.home_change_server),
                isPrimary = false,
                isOutlined = true,
            )
        }
    }
}

@Composable
private fun HomeButton(
    onClick: () -> Unit,
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    label: String,
    isPrimary: Boolean,
    isOutlined: Boolean = false,
) {
    val modifier = Modifier.fillMaxWidth()
    when {
        isOutlined -> OutlinedButton(onClick = onClick, modifier = modifier) {
            Icon(imageVector = icon, contentDescription = null)
            Text(
                text = label,
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier.padding(start = Spacing.sm),
            )
        }
        isPrimary -> Button(onClick = onClick, modifier = modifier) {
            Icon(imageVector = icon, contentDescription = null)
            Text(
                text = label,
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier.padding(start = Spacing.sm),
            )
        }
        else -> FilledTonalButton(onClick = onClick, modifier = modifier) {
            Icon(imageVector = icon, contentDescription = null)
            Text(
                text = label,
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier.padding(start = Spacing.sm),
            )
        }
    }
}

@androidx.compose.ui.tooling.preview.Preview(showBackground = true)
@Composable
private fun HomeScreenPreview() {
    AppTheme {
        HomeScreen(
            onCreateGameClick = {},
            onJoinGameClick = {},
            onChangeServerClick = {},
            onSettingsClick = {},
        )
    }
}
