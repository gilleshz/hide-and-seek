package fr.gshz.hideandseek.core.scanner

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.Settings
import android.util.Size
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalInspectionMode
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.compose.LocalLifecycleOwner
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing
import java.util.concurrent.Executors

internal const val QR_SCAN_RESULT_KEY = "qr_scan_result"

@Composable
fun QrScannerScreen(
    onQrScanned: (String) -> Unit,
    onClose: () -> Unit,
) {
    val context = LocalContext.current
    var hasCameraPermission by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) ==
                PackageManager.PERMISSION_GRANTED,
        )
    }
    val permissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission(),
    ) { granted ->
        hasCameraPermission = granted
    }

    LifecycleResumeEffect(Unit) {
        hasCameraPermission =
            ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) ==
                PackageManager.PERMISSION_GRANTED
        onPauseOrDispose { }
    }

    LaunchedEffect(Unit) {
        if (!hasCameraPermission) permissionLauncher.launch(Manifest.permission.CAMERA)
    }

    var cameraError by remember { mutableStateOf(false) }

    when {
        !hasCameraPermission -> QrScannerDeniedContent(
            onOpenSettings = { context.openAppSettings() },
            onClose = onClose,
        )
        cameraError -> QrScannerErrorContent(onClose = onClose)
        else -> QrScannerContent(
            onQrScanned = onQrScanned,
            onClose = onClose,
            onCameraError = { cameraError = true },
        )
    }
}

@Composable
internal fun QrScannerContent(
    onQrScanned: (String) -> Unit,
    onClose: () -> Unit,
    onCameraError: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier = modifier
            .fillMaxSize()
            .background(Color.Black),
    ) {
        CameraPreviewBinder(onQrScanned = onQrScanned, onCameraError = onCameraError)
        Column(
            modifier = Modifier
                .align(Alignment.Center)
                .padding(horizontal = Spacing.lg),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(
                modifier = Modifier
                    .size(width = 260.dp, height = 260.dp)
                    .border(width = 2.dp, color = Color.White),
            )
            Spacer(modifier = Modifier.height(Spacing.md))
            Text(
                text = stringResource(R.string.qr_scan_prompt),
                style = MaterialTheme.typography.bodyMedium,
                color = Color.White,
                textAlign = TextAlign.Center,
            )
        }
        QrScannerCloseButton(
            onClose = onClose,
            modifier = Modifier
                .align(Alignment.TopEnd)
                .padding(Spacing.sm),
        )
    }
}

@Composable
private fun CameraPreviewBinder(
    onQrScanned: (String) -> Unit,
    onCameraError: () -> Unit,
) {
    if (LocalInspectionMode.current) return

    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val previewView = remember {
        PreviewView(context).apply {
            implementationMode = PreviewView.ImplementationMode.COMPATIBLE
        }
    }
    val analysisExecutor = remember { Executors.newSingleThreadExecutor() }

    AndroidView(factory = { previewView }, modifier = Modifier.fillMaxSize())

    DisposableEffect(lifecycleOwner, previewView) {
        val cameraProviderFuture = ProcessCameraProvider.getInstance(context)
        var cameraProvider: ProcessCameraProvider? = null
        var disposed = false
        val bindListener = Runnable {
            if (disposed) return@Runnable
            try {
                val provider = cameraProviderFuture.get()
                cameraProvider = provider
                val preview = Preview.Builder().build().also {
                    it.setSurfaceProvider(previewView.surfaceProvider)
                }
                val analysis = ImageAnalysis.Builder()
                    .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                    .setTargetResolution(Size(640, 480))
                    .build()
                    .also { it.setAnalyzer(analysisExecutor, QrAnalyzer(onQrScanned)) }
                provider.unbindAll()
                provider.bindToLifecycle(
                    lifecycleOwner,
                    CameraSelector.DEFAULT_BACK_CAMERA,
                    preview,
                    analysis,
                )
            } catch (_: Exception) {
                if (!disposed) onCameraError()
            }
        }
        cameraProviderFuture.addListener(bindListener, ContextCompat.getMainExecutor(context))
        onDispose {
            disposed = true
            cameraProvider?.unbindAll()
            analysisExecutor.shutdown()
        }
    }
}

@Composable
private fun QrScannerDeniedContent(
    onOpenSettings: () -> Unit,
    onClose: () -> Unit,
) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Color.Black),
    ) {
        Column(
            modifier = Modifier
                .align(Alignment.Center)
                .padding(Spacing.lg),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                text = stringResource(R.string.qr_scan_permission_denied),
                style = MaterialTheme.typography.bodyLarge,
                color = Color.White,
                textAlign = TextAlign.Center,
            )
            Spacer(modifier = Modifier.height(Spacing.lg))
            Button(onClick = onOpenSettings) {
                Text(stringResource(R.string.qr_scan_open_settings))
            }
        }
        QrScannerCloseButton(
            onClose = onClose,
            modifier = Modifier
                .align(Alignment.TopEnd)
                .padding(Spacing.sm),
        )
    }
}

@Composable
private fun QrScannerErrorContent(onClose: () -> Unit) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Color.Black),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            text = stringResource(R.string.error_unknown),
            style = MaterialTheme.typography.bodyLarge,
            color = Color.White,
            textAlign = TextAlign.Center,
            modifier = Modifier.padding(horizontal = Spacing.lg),
        )
        QrScannerCloseButton(
            onClose = onClose,
            modifier = Modifier
                .align(Alignment.TopEnd)
                .padding(Spacing.sm),
        )
    }
}

@Composable
private fun QrScannerCloseButton(
    onClose: () -> Unit,
    modifier: Modifier = Modifier,
) {
    // Always a top-corner overlay over the camera: stay below the status bar.
    IconButton(onClick = onClose, modifier = modifier.statusBarsPadding()) {
        Icon(
            imageVector = Icons.Filled.Close,
            contentDescription = stringResource(R.string.qr_scan_close),
            tint = Color.White,
        )
    }
}

private fun Context.openAppSettings() {
    val intent = Intent(
        Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
        Uri.fromParts("package", packageName, null),
    )
    startActivity(intent)
}

@androidx.compose.ui.tooling.preview.Preview(showBackground = true)
@Composable
private fun QrScannerContentPreview() {
    AppTheme {
        QrScannerContent(
            onQrScanned = {},
            onClose = {},
            onCameraError = {},
        )
    }
}
