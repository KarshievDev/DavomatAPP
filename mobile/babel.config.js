module.exports = function (api) {
  api.cache(true);
  return {
    presets: ['babel-preset-expo'],
    plugins: [
      // react-native-worklets/plugin must be listed, and usually at the end.
      'react-native-worklets/plugin',
    ],
  };
};
