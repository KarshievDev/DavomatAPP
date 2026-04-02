import React, { useEffect, useRef } from 'react';
import { Animated, StyleSheet, Text, View } from 'react-native';

export default function Loader({ message = 'Yuklanmoqda...' }: { message?: string }) {
    const pulseAnim = useRef(new Animated.Value(1)).current;

    useEffect(() => {
        Animated.loop(
            Animated.sequence([
                Animated.timing(pulseAnim, { toValue: 1.15, duration: 800, useNativeDriver: true }),
                Animated.timing(pulseAnim, { toValue: 1, duration: 800, useNativeDriver: true })
            ])
        ).start();
    }, [pulseAnim]);

    return (
        <View style={styles.container}>
            <Animated.Image
                source={require('../assets/images/workpay_logo.png')}
                style={[styles.logo, { transform: [{ scale: pulseAnim }] }]}
                resizeMode="contain"
            />
            <Text style={styles.text}>{message}</Text>
        </View>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        justifyContent: 'center',
        alignItems: 'center',
        backgroundColor: '#F3F4F6'
    },
    logo: {
        width: 100,
        height: 100,
        borderRadius: 20,
    },
    text: {
        marginTop: 20,
        fontSize: 16,
        fontWeight: 'bold',
        color: '#E11D48',
        letterSpacing: 2,
    }
});
