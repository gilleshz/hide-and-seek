package fr.gshz.hideandseek.core.ui

import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing

/**
 * The app brand lockup: the logo and the app name. Reused as the entrance on
 * Connect and Home.
 */
@Composable
fun BrandWordmark(modifier: Modifier = Modifier, compact: Boolean = false) {
    Column(
        modifier = modifier,
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(Spacing.md),
    ) {
        BrandBadge(size = if (compact) 56.dp else 88.dp)
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(Spacing.xs),
        ) {
            Text(
                text = stringResource(R.string.app_name),
                style = if (compact) MaterialTheme.typography.headlineMedium else MaterialTheme.typography.displaySmall,
                color = MaterialTheme.colorScheme.onSurface,
                textAlign = TextAlign.Center,
            )
        }
    }
}

@Composable
private fun BrandBadge(size: Dp) {
    Image(
        painter = painterResource(R.drawable.app_logo),
        contentDescription = null,
        contentScale = ContentScale.Crop,
        modifier = Modifier
            .size(size)
            .clip(RoundedCornerShape(percent = 20)),
    )
}

@Preview
@Composable
private fun BrandWordmarkPreview() {
    AppTheme { BrandWordmark(modifier = Modifier.padding(Spacing.lg)) }
}

@Preview
@Composable
private fun BrandWordmarkDarkPreview() {
    AppTheme(themeMode = "dark") { BrandWordmark(modifier = Modifier.padding(Spacing.lg)) }
}
